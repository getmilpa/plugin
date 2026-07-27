<?php

/**
 * This file is part of Milpa Plugin — the GitHub-native plugin distribution
 * core of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/plugin
 */

declare(strict_types=1);

namespace Milpa\Plugin;

use Milpa\DTO\DependencyResolution;
use Milpa\DTO\PluginInstallResult;
use Milpa\DTO\PluginRemoveResult;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInstallerInterface;
use Milpa\Plugin\Contracts\ComposerRunnerInterface;
use Milpa\Plugin\Contracts\PluginDownloaderInterface;
use Milpa\Plugin\Contracts\PluginRecord;
use Milpa\Plugin\Contracts\PluginRegistryInterface;
use Milpa\ValueObjects\SemanticVersion;

/**
 * Plugin install/update/remove pipeline, port-shaped: the source of the code
 * speaks {@see PluginDownloaderInterface}, the activation store speaks
 * {@see PluginRegistryInterface}, migrations run through the v2
 * {@see PluginMigrationRunner}, and composer requires go through
 * {@see ComposerRunnerInterface} — a failed or invalid package spec ABORTS the
 * installation (never the legacy log-and-continue).
 */
final class PluginInstaller implements PluginInstallerInterface
{
    /** Composer's canonical package-name pattern (vendor/package, lowercase). */
    private const PACKAGE_NAME_PATTERN = '{^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$}D';

    /** @var callable|null Output callback for progress messages */
    private $outputCallback = null;

    private readonly string $pluginsDir;

    private const PLUGINS_NAMESPACE = 'Milpa\\Plugins\\';

    public function __construct(
        private readonly DIContainerInterface $container,
        private readonly PluginRegistryInterface $registry,
        private readonly PluginMigrationRunner $migrationRunner,
        private readonly ComposerRunnerInterface $composerRunner,
        private readonly PluginDownloaderInterface $downloader,
        private readonly DependencyResolver $resolver,
        private readonly LockFileManager $lockManager,
        private readonly string $rootPath,
    ) {
        $this->pluginsDir = $rootPath . '/plugins';
    }

    /**
     * Set a callback for output messages during installation.
     *
     * @param callable(string $message, string $type): void $callback
     */
    public function setOutputCallback(callable $callback): void
    {
        $this->outputCallback = $callback;
    }

    /**
     * Install a plugin from a remote source (GitHub).
     *
     * Flow:
     *   1. Parse source → owner, repo, constraint
     *   2. Query GitHub API → find matching release
     *   3. Download and extract zipball
     *   4. Read milpa.json → validate
     *   5. Resolve dependencies (Composer + plugins)
     *   6. Install Composer packages through the composer port — a failed or
     *      invalid package spec ABORTS before any file or registry change
     *   7. Copy to plugins/ directory
     *   8. Register in the activation store + run install()
     *   9. Run migrations
     *  10. Update milpa.lock
     */
    public function require(string $source): PluginInstallResult
    {
        try {
            // 1. Parse source
            $parsed = $this->downloader->parseSource($source);
            $owner = $parsed['owner'];
            $repo = $parsed['repo'];
            $constraint = $parsed['constraint'];

            $this->output("Resolving {$owner}/{$repo}...", 'info');

            // 2. Find matching version
            $version = $this->downloader->resolveVersion($owner, $repo, $constraint);
            $constraintLabel = $constraint !== null ? " (satisfies {$constraint})" : '';
            $this->output("Found: v{$version}{$constraintLabel}", 'info');

            // 3. Download and extract
            $this->output("Downloading plugin...", 'info');
            $extractedPath = $this->downloader->download($owner, $repo, $version);

            // 4. Read and validate milpa.json
            $manifestPath = $extractedPath . '/milpa.json';
            if (!file_exists($manifestPath)) {
                $this->downloader->cleanup(dirname($extractedPath));
                return new PluginInstallResult(
                    success: false,
                    pluginName: $repo,
                    version: (string) $version,
                    source: "github:{$owner}/{$repo}",
                    error: "Plugin has no milpa.json manifest. Remote plugins must include a milpa.json."
                );
            }

            $manifest = PluginManifest::fromPath($manifestPath);
            $manifest->validate();

            // Derive plugin name from namespace (e.g., "Milpa\Plugins\MailPlugin" → "MailPlugin")
            $namespace = $manifest->getNamespace();
            $pluginName = $this->extractPluginName($namespace);

            $this->output("Plugin: {$pluginName} v{$version}", 'info');

            // Check if already installed
            if ($this->registry->find($pluginName) !== null) {
                $this->downloader->cleanup(dirname($extractedPath));
                return new PluginInstallResult(
                    success: false,
                    pluginName: $pluginName,
                    version: (string) $version,
                    source: "github:{$owner}/{$repo}",
                    error: "Plugin {$pluginName} is already installed. Use 'update' to upgrade."
                );
            }

            // 5. Resolve dependencies
            $this->output("Checking dependencies...", 'info');
            $installedPlugins = $this->getInstalledPluginsMetadata();
            $resolution = $this->resolver->resolve($manifest, $installedPlugins);

            if (!empty($resolution->missingPlugins)) {
                $this->downloader->cleanup(dirname($extractedPath));
                return new PluginInstallResult(
                    success: false,
                    pluginName: $pluginName,
                    version: (string) $version,
                    source: "github:{$owner}/{$repo}",
                    error: "Missing required plugins: " . implode(', ', $resolution->missingPlugins)
                );
            }

            if (!empty($resolution->conflicts)) {
                $this->downloader->cleanup(dirname($extractedPath));
                return new PluginInstallResult(
                    success: false,
                    pluginName: $pluginName,
                    version: (string) $version,
                    source: "github:{$owner}/{$repo}",
                    error: "Dependency conflicts:\n  " . implode("\n  ", $resolution->conflicts)
                );
            }

            // 6. Install Composer dependencies — validated, then run through
            // the composer port; ANY failure aborts before files or registry
            // state are touched (D6).
            $composerInstalled = [];
            if (!empty($resolution->composerPackages)) {
                $offender = $this->firstInvalidPackageName($resolution->composerPackages);
                if ($offender !== null) {
                    $this->downloader->cleanup(dirname($extractedPath));
                    return new PluginInstallResult(
                        success: false,
                        pluginName: $pluginName,
                        version: (string) $version,
                        source: "github:{$owner}/{$repo}",
                        error: "Refusing composer dependency '{$offender}': not a valid composer package name (option-injection guard).",
                    );
                }

                $this->output("Installing Composer dependencies...", 'info');
                $composerResult = $this->composerRunner->requirePackages($this->rootPath, $resolution->composerPackages);
                if (!$composerResult->success) {
                    $this->downloader->cleanup(dirname($extractedPath));
                    return new PluginInstallResult(
                        success: false,
                        pluginName: $pluginName,
                        version: (string) $version,
                        source: "github:{$owner}/{$repo}",
                        composerPackagesInstalled: $composerResult->installed,
                        error: "composer require failed for '{$composerResult->failedPackage}' — installation aborted before any file or registry change.\n" . $composerResult->output,
                    );
                }
                $composerInstalled = $composerResult->installed;
            }

            // 7. Copy to plugins directory
            $targetDir = $this->pluginsDir . '/' . $pluginName;
            if (is_dir($targetDir)) {
                $this->downloader->cleanup(dirname($extractedPath));
                return new PluginInstallResult(
                    success: false,
                    pluginName: $pluginName,
                    version: (string) $version,
                    source: "github:{$owner}/{$repo}",
                    error: "Directory already exists: plugins/{$pluginName}/"
                );
            }

            $this->output("Copying to plugins/{$pluginName}/...", 'info');
            $this->copyDirectory($extractedPath, $targetDir);
            $this->downloader->cleanup(dirname($extractedPath));

            // 8. Register in the activation store and run install()
            $this->output("Registering plugin...", 'info');
            $metadata = $manifest->toMetadataArray();

            $this->registry->register(new PluginRecord(
                name: $pluginName,
                version: $metadata['version'],
                author: $metadata['author'],
                site: $metadata['site'],
                type: $metadata['type'],
                installed: true,
                enabled: false,
                source: "github:{$owner}/{$repo}",
                installedVersion: (string) $version,
                installedAt: new \DateTimeImmutable(),
                composerDeps: $composerInstalled !== [] ? $composerInstalled : null,
            ));

            // Run plugin's install() method if class exists
            $className = self::PLUGINS_NAMESPACE . "{$pluginName}\\{$pluginName}";
            $migrationsExecuted = 0;

            // Require the entrypoint to autoload the class
            $entrypointFile = $targetDir . '/' . $manifest->getEntrypoint();
            if (file_exists($entrypointFile) && !class_exists($className)) {
                require_once $entrypointFile;
            }

            if (class_exists($className)) {
                try {
                    $pluginInstance = new $className($this->container);
                    $pluginInstance->install();
                    $this->output("Plugin install() executed", 'info');
                } catch (\Exception $e) {
                    $this->output("Warning: install() failed: {$e->getMessage()}", 'warning');
                }
            }

            // 9. Run migrations
            $migrationsDir = $targetDir . '/' . ($manifest->getMigrationsDirectory() ?? 'Migrations');
            if (is_dir($migrationsDir)) {
                $this->output("Running migrations...", 'info');
                $migResult = $this->migrationRunner->migrate($pluginName, $migrationsDir, $namespace);
                $migrationsExecuted = $migResult['executed'];
                foreach ($migResult['migrations'] as $mig) {
                    $this->output("  {$mig['version']} — {$mig['description']}", 'migration');
                }
            }

            // 10. Update lock file
            $this->updateLockFile();

            return new PluginInstallResult(
                success: true,
                pluginName: $pluginName,
                version: (string) $version,
                source: "github:{$owner}/{$repo}",
                composerPackagesInstalled: $composerInstalled,
                migrationsExecuted: $migrationsExecuted,
            );

        } catch (\Exception $e) {
            return new PluginInstallResult(
                success: false,
                pluginName: $source,
                version: '0.0.0',
                source: $source,
                error: $e->getMessage(),
            );
        }
    }

    /**
     * Update an installed plugin to the latest compatible version.
     */
    public function update(string $pluginName, ?string $targetVersion = null): PluginInstallResult
    {
        try {
            // Find the plugin in the activation store
            $record = $this->registry->find($pluginName);
            if ($record === null) {
                return new PluginInstallResult(
                    success: false,
                    pluginName: $pluginName,
                    version: '0.0.0',
                    source: 'unknown',
                    error: "Plugin {$pluginName} is not installed."
                );
            }

            $source = $record->source;
            if ($source === null || $source === 'local') {
                return new PluginInstallResult(
                    success: false,
                    pluginName: $pluginName,
                    version: $record->version,
                    source: 'local',
                    error: "Plugin {$pluginName} was installed locally. Cannot update from GitHub."
                );
            }

            // Parse github:owner/repo
            $githubSource = str_replace('github:', '', $source);
            $parsed = $this->downloader->parseSource($githubSource);
            $owner = $parsed['owner'];
            $repo = $parsed['repo'];

            $this->output("Checking updates for {$pluginName}...", 'info');

            // Resolve version
            $constraint = $targetVersion;
            $newVersion = $this->downloader->resolveVersion($owner, $repo, $constraint);
            $currentVersion = SemanticVersion::tryParse($record->installedVersion ?? $record->version);

            if ($currentVersion !== null && $newVersion->equals($currentVersion)) {
                return new PluginInstallResult(
                    success: true,
                    pluginName: $pluginName,
                    version: (string) $newVersion,
                    source: $source,
                    error: "Already at latest version v{$newVersion}"
                );
            }

            if ($currentVersion !== null && $newVersion->lessThan($currentVersion)) {
                return new PluginInstallResult(
                    success: false,
                    pluginName: $pluginName,
                    version: (string) $currentVersion,
                    source: $source,
                    error: "Target version v{$newVersion} is older than installed v{$currentVersion}"
                );
            }

            $this->output("Updating {$pluginName}: v{$currentVersion} -> v{$newVersion}", 'info');

            // Download new version
            $extractedPath = $this->downloader->download($owner, $repo, $newVersion);

            // Read manifest
            $manifestPath = $extractedPath . '/milpa.json';
            if (!file_exists($manifestPath)) {
                $this->downloader->cleanup(dirname($extractedPath));
                return new PluginInstallResult(
                    success: false,
                    pluginName: $pluginName,
                    version: (string) $newVersion,
                    source: $source,
                    error: "New version has no milpa.json manifest."
                );
            }

            $manifest = PluginManifest::fromPath($manifestPath);
            $manifest->validate();
            $namespace = $manifest->getNamespace();

            // Resolve dependencies
            $installedPlugins = $this->getInstalledPluginsMetadata();
            $resolution = $this->resolver->resolve($manifest, $installedPlugins);

            // Install new Composer deps — validated, then run through the
            // composer port; ANY failure aborts before the plugin directory
            // or the activation store are touched (D6, mirrors require()).
            $composerInstalled = [];
            if (!empty($resolution->composerPackages)) {
                $offender = $this->firstInvalidPackageName($resolution->composerPackages);
                if ($offender !== null) {
                    $this->downloader->cleanup(dirname($extractedPath));
                    return new PluginInstallResult(
                        success: false,
                        pluginName: $pluginName,
                        version: (string) $newVersion,
                        source: $source,
                        error: "Refusing composer dependency '{$offender}': not a valid composer package name (option-injection guard).",
                    );
                }

                $this->output("Installing new Composer dependencies...", 'info');
                $composerResult = $this->composerRunner->requirePackages($this->rootPath, $resolution->composerPackages);
                if (!$composerResult->success) {
                    $this->downloader->cleanup(dirname($extractedPath));
                    return new PluginInstallResult(
                        success: false,
                        pluginName: $pluginName,
                        version: (string) $newVersion,
                        source: $source,
                        composerPackagesInstalled: $composerResult->installed,
                        error: "composer require failed for '{$composerResult->failedPackage}' — update aborted before any file or registry change.\n" . $composerResult->output,
                    );
                }
                $composerInstalled = $composerResult->installed;
            }

            // Replace plugin directory
            $targetDir = $this->pluginsDir . '/' . $pluginName;
            if (is_dir($targetDir)) {
                // Preserve Records/ directory if it exists
                $recordsDir = $targetDir . '/Records';
                $tempRecords = null;
                if (is_dir($recordsDir)) {
                    $tempRecords = sys_get_temp_dir() . '/milpa_records_' . uniqid();
                    $this->copyDirectory($recordsDir, $tempRecords);
                }

                $this->downloader->cleanup($targetDir);

                $this->copyDirectory($extractedPath, $targetDir);

                // Restore Records/
                if ($tempRecords !== null && is_dir($tempRecords)) {
                    $this->copyDirectory($tempRecords, $targetDir . '/Records');
                    $this->downloader->cleanup($tempRecords);
                }
            } else {
                $this->copyDirectory($extractedPath, $targetDir);
            }

            $this->downloader->cleanup(dirname($extractedPath));

            // Update the activation store
            $metadata = $manifest->toMetadataArray();
            $this->registry->save(new PluginRecord(
                name: $pluginName,
                version: $metadata['version'],
                author: $metadata['author'],
                site: $record->site,
                type: $metadata['type'],
                installed: $record->installed,
                enabled: $record->enabled,
                source: $record->source,
                installedVersion: (string) $newVersion,
                installedAt: new \DateTimeImmutable(),
                composerDeps: $composerInstalled !== []
                    ? array_merge($record->composerDeps ?? [], $composerInstalled)
                    : $record->composerDeps,
            ));

            // Run migrations
            $migrationsExecuted = 0;
            $migrationsDir = $targetDir . '/' . ($manifest->getMigrationsDirectory() ?? 'Migrations');
            if (is_dir($migrationsDir)) {
                $this->output("Running migrations...", 'info');
                $migResult = $this->migrationRunner->migrate($pluginName, $migrationsDir, $namespace);
                $migrationsExecuted = $migResult['executed'];
                foreach ($migResult['migrations'] as $mig) {
                    $this->output("  {$mig['version']} — {$mig['description']}", 'migration');
                }
            }

            // Update lock file
            $this->updateLockFile();

            return new PluginInstallResult(
                success: true,
                pluginName: $pluginName,
                version: (string) $newVersion,
                source: $source,
                composerPackagesInstalled: $composerInstalled,
                migrationsExecuted: $migrationsExecuted,
            );

        } catch (\Exception $e) {
            return new PluginInstallResult(
                success: false,
                pluginName: $pluginName,
                version: '0.0.0',
                source: 'unknown',
                error: $e->getMessage(),
            );
        }
    }

    /**
     * Resolve plugin and Composer dependencies for a remote source without installing it.
     *
     * Mirrors steps 1-5 of {@see require()} (parse source, resolve version, download,
     * read manifest, resolve dependencies) but never copies files, touches the
     * activation store, or updates milpa.lock — the extracted download is always
     * cleaned up before returning.
     */
    public function resolve(string $source): DependencyResolution
    {
        $extractedPath = null;

        try {
            $parsed = $this->downloader->parseSource($source);
            $owner = $parsed['owner'];
            $repo = $parsed['repo'];
            $constraint = $parsed['constraint'];

            $version = $this->downloader->resolveVersion($owner, $repo, $constraint);
            $extractedPath = $this->downloader->download($owner, $repo, $version);

            $manifestPath = $extractedPath . '/milpa.json';
            if (!file_exists($manifestPath)) {
                return new DependencyResolution(
                    resolvable: false,
                    conflicts: ["Plugin has no milpa.json manifest. Remote plugins must include a milpa.json."],
                );
            }

            $manifest = PluginManifest::fromPath($manifestPath);
            $manifest->validate();

            $installedPlugins = $this->getInstalledPluginsMetadata();

            return $this->resolver->resolve($manifest, $installedPlugins);
        } catch (\Exception $e) {
            return new DependencyResolution(
                resolvable: false,
                conflicts: [$e->getMessage()],
            );
        } finally {
            if ($extractedPath !== null) {
                $this->downloader->cleanup(dirname($extractedPath));
            }
        }
    }

    /**
     * Remove a remotely-installed plugin.
     */
    public function remove(string $pluginName, bool $keepData = false): PluginRemoveResult
    {
        $record = $this->registry->find($pluginName);
        if ($record === null) {
            return PluginRemoveResult::failure($pluginName, "Plugin {$pluginName} is not installed.");
        }

        $source = $record->source;
        if ($source === null || $source === 'local') {
            return PluginRemoveResult::failure($pluginName, "Plugin {$pluginName} was installed locally and cannot be removed via the remote installer.");
        }

        $targetDir = $this->pluginsDir . '/' . $pluginName;

        // Run uninstall() if plugin class exists
        $className = self::PLUGINS_NAMESPACE . "{$pluginName}\\{$pluginName}";
        if (class_exists($className)) {
            try {
                $pluginInstance = new $className($this->container);
                $pluginInstance->uninstall();
            } catch (\Exception) {
                // Continue with removal even if uninstall() fails
            }
        }

        // Remove from the activation store
        $this->registry->unregister($pluginName);

        // Remove plugin directory
        if (!$keepData && is_dir($targetDir)) {
            $this->downloader->cleanup($targetDir);
        }

        // Update lock file
        $this->updateLockFile();

        return PluginRemoveResult::success($pluginName, dataKept: $keepData);
    }

    /**
     * Check if updates are available for installed remote plugins.
     *
     * @return array<array{name: string, current: string, latest: string, source: string}>
     */
    public function checkOutdated(): array
    {
        $records = $this->registry->installed();
        $outdated = [];

        foreach ($records as $record) {
            $source = $record->source;
            if ($source === null || $source === 'local') {
                continue;
            }

            try {
                $githubSource = str_replace('github:', '', $source);
                $parsed = $this->downloader->parseSource($githubSource);
                $latest = $this->downloader->resolveVersion($parsed['owner'], $parsed['repo']);
                $current = SemanticVersion::tryParse($record->installedVersion ?? $record->version);

                if ($current !== null && $latest->greaterThan($current)) {
                    $outdated[] = [
                        'name' => $record->name,
                        'current' => (string) $current,
                        'latest' => (string) $latest,
                        'source' => $source,
                    ];
                }
            } catch (\Exception) {
                // Skip plugins we can't check
            }
        }

        return $outdated;
    }

    /**
     * First composer package name that fails composer's canonical pattern, or
     * null when every name is clean. A name starting with '-' would otherwise
     * reach composer's argv as an option — the 6a review's injection vector.
     *
     * @param array<string, string> $packages
     */
    private function firstInvalidPackageName(array $packages): ?string
    {
        foreach (array_keys($packages) as $package) {
            if (!preg_match(self::PACKAGE_NAME_PATTERN, $package)) {
                return $package;
            }
        }

        return null;
    }

    /**
     * Copy directory recursively.
     */
    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($destination)) {
            mkdir($destination, 0755, true);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $targetPath = $destination . '/' . $iterator->getSubPathname();

            if ($item->isDir()) {
                if (!is_dir($targetPath)) {
                    mkdir($targetPath, 0755, true);
                }
            } else {
                copy($item->getPathname(), $targetPath);
            }
        }
    }

    /**
     * Update milpa.lock after any install/update/remove operation.
     */
    private function updateLockFile(): void
    {
        $installedPlugins = $this->registry->installed();

        $pluginsData = [];
        foreach ($installedPlugins as $record) {
            $pluginsData[] = [
                'name' => $record->name,
                'version' => $record->version,
                'source' => $record->source ?? 'local',
                'installedAt' => $record->installedAt?->format('c') ?? (new \DateTimeImmutable())->format('c'),
                'composerDeps' => $record->composerDeps,
            ];
        }

        $this->lockManager->generate($pluginsData);
    }

    /**
     * Get metadata for all installed & enabled plugins.
     *
     * @return list<array<string, mixed>>
     */
    private function getInstalledPluginsMetadata(): array
    {
        $records = $this->registry->installedAndEnabled();
        $metadata = [];

        foreach ($records as $record) {
            $pluginName = $record->name;
            $pluginDir = $this->pluginsDir . '/' . $pluginName;
            $manifestPath = $pluginDir . '/milpa.json';

            if (file_exists($manifestPath)) {
                try {
                    $manifest = PluginManifest::fromPath($manifestPath);
                    $meta = $manifest->toMetadataArray();
                    $meta['name'] = $pluginName;
                    $metadata[] = $meta;
                    continue;
                } catch (\Exception) {
                    // Fall through to basic info
                }
            }

            // Fallback: basic info from the record
            $metadata[] = [
                'name' => $pluginName,
                'version' => $record->version,
                'provides' => [],
                'requires' => [],
            ];
        }

        return $metadata;
    }

    /**
     * Extract plugin name from namespace.
     * "Milpa\Plugins\MailPlugin" → "MailPlugin"
     */
    private function extractPluginName(string $namespace): string
    {
        $parts = explode('\\', rtrim($namespace, '\\'));
        return end($parts);
    }

    /**
     * Output a message via the callback if set.
     */
    private function output(string $message, string $type = 'info'): void
    {
        if ($this->outputCallback !== null) {
            ($this->outputCallback)($message, $type);
        }
    }
}
