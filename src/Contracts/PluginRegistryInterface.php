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

namespace Milpa\Plugin\Contracts;

/**
 * The plugin activation/installation store, decoupled from any persistence
 * technology. The host implements it over its database; the package ships
 * in-memory and file-backed implementations.
 *
 * Semantics every implementation MUST honor:
 *  - enabledNames() MUST NOT throw when the backing store is unavailable —
 *    it returns [] (the boot path treats "store down" as "nothing enabled").
 *  - register() throws \RuntimeException when the name is already registered.
 *  - save() and setEnabled() throw \RuntimeException when the name is unknown.
 *  - unregister() on an unknown name is a no-op (idempotent).
 */
interface PluginRegistryInterface
{
    /**
     * Names of all enabled plugins — the ONLY registry read the boot path performs.
     *
     * @return list<string>
     */
    public function enabledNames(): array;

    /** Find one plugin's record by name, or null when not registered. */
    public function find(string $name): ?PluginRecord;

    /**
     * All installed plugins.
     *
     * @return list<PluginRecord>
     */
    public function installed(): array;

    /**
     * All plugins that are both installed and enabled.
     *
     * @return list<PluginRecord>
     */
    public function installedAndEnabled(): array;

    /**
     * Register a new plugin.
     *
     * @throws \RuntimeException When a plugin with the same name is already registered.
     */
    public function register(PluginRecord $record): void;

    /**
     * Overwrite an existing plugin's record (keyed by $record->name).
     *
     * @throws \RuntimeException When the plugin is not registered.
     */
    public function save(PluginRecord $record): void;

    /**
     * Flip a plugin's enabled flag.
     *
     * @throws \RuntimeException When the plugin is not registered.
     */
    public function setEnabled(string $name, bool $enabled): void;

    /** Remove a plugin from the registry. Unknown name: no-op. */
    public function unregister(string $name): void;

    /**
     * Invalidate every materialized activation cache so the next boot rebuilds
     * from the store. For the host this means BOTH cache files
     * (enabled_plugins.php AND plugins.php); pure implementations no-op.
     */
    public function invalidateActivationCache(): void;
}
