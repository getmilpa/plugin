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
use Milpa\ValueObjects\SemanticVersion;

/**
 * Resolves plugin and Composer dependencies before installation.
 *
 * Checks:
 * - Plugin dependencies (dependencies.plugins in milpa.json)
 * - Composer packages (dependencies.composer in milpa.json)
 * - Capability requirements (canonical `capabilities.requires` records or
 *   legacy `contracts.requires` bare FQCNs — both shapes, via the manifest's
 *   typed readers)
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
     * Capability matching is by IDENTITY: an installed provision satisfies a requirement when
     * its capability id or its interface equals the requirement's `id`, `interface`, or any
     * `oneOf` alternative — the same one-directional superset posture core's
     * CapabilityGraphChecker takes (an install preview must not fail a graph the resolver would
     * close via the interface bridge or `oneOf`). Legacy bare-FQCN declarations on either side
     * behave exactly as before: the FQCN is both id and interface.
     *
     * @param PluginManifest                                                                                                             $manifest         The plugin to install
     * @param array<array{name: string, version?: string, provides?: array<int, string|array<string, mixed>>, requires?: array<string>}> $installedPlugins
     */
    public function resolve(PluginManifest $manifest, array $installedPlugins): DependencyResolution
    {
        $conflicts = [];
        $missingPlugins = [];
        $satisfiedContracts = [];

        // 1. Check capability requirements against every installed provision identity
        $provided = [];
        foreach ($installedPlugins as $plugin) {
            foreach ($plugin['provides'] ?? [] as $entry) {
                foreach ($this->identitiesOf($entry) as $identity) {
                    $provided[$identity] = true;
                }
            }
        }

        foreach ($manifest->getRequiredCapabilities() as $requirement) {
            $candidates = [$requirement->id, $requirement->interface, ...$requirement->oneOf];
            $satisfied = false;
            foreach ($candidates as $candidate) {
                if (isset($provided[ltrim($candidate, '\\')])) {
                    $satisfied = true;
                    break;
                }
            }

            if ($satisfied) {
                $satisfiedContracts[] = $requirement->id;
            } else {
                $conflicts[] = "Missing contract: {$requirement->id}";
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
     * The identities one provision entry answers to: a bare FQCN string is its own (single)
     * identity; a canonical record answers to both its `id` and its `interface`. Normalized
     * with `ltrim('\\')` so a leading-backslash FQCN and its bare form are equal (mirroring
     * the resolver's DriftDetector).
     *
     * @return list<string>
     */
    private function identitiesOf(mixed $entry): array
    {
        if (is_string($entry) && trim($entry) !== '') {
            return [ltrim(trim($entry), '\\')];
        }

        if (is_array($entry)) {
            $identities = [];
            foreach (['id', 'interface'] as $key) {
                $value = $entry[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $identities[] = ltrim(trim($value), '\\');
                }
            }

            return $identities;
        }

        return [];
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
