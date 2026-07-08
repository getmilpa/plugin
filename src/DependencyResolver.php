<?php

/**
 * This file is part of Milpa Plugin — the GitHub-native plugin distribution
 * core of the Milpa PHP framework.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/plugin
 */

declare(strict_types=1);

namespace Milpa\Plugin;

use Milpa\DTO\DependencyResolution;
use Milpa\ValueObjects\SemanticVersion;

/**
 * Resolves plugin and Composer dependencies before installation.
 *
 * Checks:
 * - Plugin dependencies (dependencies.plugins in milpa.json)
 * - Composer packages (dependencies.composer in milpa.json)
 * - Contract requirements (contracts.requires)
 */
final class DependencyResolver
{
    private string $rootPath;

    public function __construct(string $rootPath)
    {
        $this->rootPath = $rootPath;
    }

    /**
     * Resolve all dependencies for a plugin to be installed.
     *
     * @param PluginManifest                                                                                   $manifest         The plugin to install
     * @param array<array{name: string, version?: string, provides?: array<string>, requires?: array<string>}> $installedPlugins
     */
    public function resolve(PluginManifest $manifest, array $installedPlugins): DependencyResolution
    {
        $conflicts = [];
        $missingPlugins = [];
        $satisfiedContracts = [];

        // 1. Check contract requirements
        $allProvided = [];
        foreach ($installedPlugins as $plugin) {
            foreach ($plugin['provides'] ?? [] as $contract) {
                $allProvided[] = $contract;
            }
        }

        foreach ($manifest->getRequires() as $contract) {
            if (in_array($contract, $allProvided, true)) {
                $satisfiedContracts[] = $contract;
            } else {
                $conflicts[] = "Missing contract: {$contract}";
            }
        }

        // 2. Check plugin dependencies
        $pluginDeps = $manifest->getPluginDependencies();
        $installedByName = [];
        foreach ($installedPlugins as $plugin) {
            $installedByName[$plugin['name']] = $plugin;
        }

        foreach ($pluginDeps as $depName => $constraint) {
            if (!isset($installedByName[$depName])) {
                $missingPlugins[] = $depName;
                continue;
            }

            // Check version constraint
            $installedVersion = SemanticVersion::tryParse($installedByName[$depName]['version'] ?? '0.0.0');
            if ($installedVersion !== null && $constraint !== '*' && !$installedVersion->satisfies($constraint)) {
                $conflicts[] = "Plugin {$depName} v{$installedVersion} does not satisfy {$constraint}";
            }
        }

        // 3. Check Composer dependencies
        $composerPackages = $this->checkComposerDeps($manifest->getComposerDependencies());

        // Determine if resolvable
        $resolvable = empty($conflicts) && empty($missingPlugins);

        return new DependencyResolution(
            resolvable: $resolvable,
            composerPackages: $composerPackages,
            missingPlugins: $missingPlugins,
            conflicts: $conflicts,
            satisfiedContracts: $satisfiedContracts,
        );
    }

    /**
     * Check Composer dependencies against what's currently installed.
     *
     * Reads root composer.lock to check installed versions.
     *
     * @param array<string, string> $requiredPackages Package => constraint
     *
     * @return array<string, string> Packages that need to be installed (package => constraint)
     */
    public function checkComposerDeps(array $requiredPackages): array
    {
        if (empty($requiredPackages)) {
            return [];
        }

        $installedPackages = $this->getInstalledComposerPackages();
        $toInstall = [];

        foreach ($requiredPackages as $package => $constraint) {
            if (!isset($installedPackages[$package])) {
                $toInstall[$package] = $constraint;
                continue;
            }

            // Package is installed — check if version satisfies constraint
            $installedVersion = SemanticVersion::tryParse($installedPackages[$package]);
            if ($installedVersion !== null && $constraint !== '*') {
                if (!$installedVersion->satisfies($constraint)) {
                    $toInstall[$package] = $constraint;
                }
            }
        }

        return $toInstall;
    }

    /**
     * Read installed Composer packages from composer.lock.
     *
     * @return array<string, string> Package name => version
     */
    private function getInstalledComposerPackages(): array
    {
        $lockFile = $this->rootPath . '/composer.lock';

        if (!file_exists($lockFile)) {
            return [];
        }

        $json = file_get_contents($lockFile);
        if ($json === false) {
            return [];
        }

        $lock = json_decode($json, true);
        if (!is_array($lock)) {
            return [];
        }

        $packages = [];

        foreach ($lock['packages'] ?? [] as $pkg) {
            if (isset($pkg['name'], $pkg['version'])) {
                $version = ltrim($pkg['version'], 'v');
                $packages[$pkg['name']] = $version;
            }
        }

        foreach ($lock['packages-dev'] ?? [] as $pkg) {
            if (isset($pkg['name'], $pkg['version'])) {
                $version = ltrim($pkg['version'], 'v');
                $packages[$pkg['name']] = $version;
            }
        }

        return $packages;
    }
}
