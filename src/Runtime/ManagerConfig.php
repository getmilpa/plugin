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

namespace Milpa\Plugin\Runtime;

/**
 * Host-injected configuration for the PluginsManager: every value the legacy
 * manager used to read from globals, env, or static kernel calls.
 */
final readonly class ManagerConfig
{
    /**
     * @param string      $cacheDir         Directory holding enabled_plugins.php and plugins.php.
     * @param string|null $hostManifestPath Path to the host's milpa.json (hostProfile), or null when the host has none.
     * @param bool        $devMode          True skips the plugin-graph cache entirely (scan fresh every boot).
     * @param string      $environment      'CLI' or 'Web' — gates tool registration by plugin type.
     * @param string      $namespacePrefix  Base namespace plugin classes are resolved under.
     */
    public function __construct(
        public string $cacheDir,
        public ?string $hostManifestPath,
        public bool $devMode,
        public string $environment,
        public string $namespacePrefix = 'Milpa\\Plugins',
    ) {
    }
}
