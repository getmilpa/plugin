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

namespace Milpa\Plugin\Registry;

use Milpa\Plugin\Contracts\PluginRecord;
use Milpa\Plugin\Contracts\PluginRegistryInterface;

/**
 * Array-backed registry: the reference implementation of the contract,
 * used by tests and by hosts with no persistence at all.
 */
final class InMemoryPluginRegistry implements PluginRegistryInterface
{
    /** @var array<string, PluginRecord> keyed by plugin name */
    private array $records = [];

    /** Names of all enabled plugins; never throws — a broken store reads as []. */
    public function enabledNames(): array
    {
        $names = [];
        foreach ($this->records as $record) {
            if ($record->enabled) {
                $names[] = $record->name;
            }
        }

        return $names;
    }

    /** Find one plugin's record by name, or null when not registered. */
    public function find(string $name): ?PluginRecord
    {
        return $this->records[$name] ?? null;
    }

    /** All installed plugins. */
    public function installed(): array
    {
        return array_values(array_filter($this->records, fn (PluginRecord $r): bool => $r->installed));
    }

    /** All plugins that are both installed and enabled. */
    public function installedAndEnabled(): array
    {
        return array_values(array_filter($this->records, fn (PluginRecord $r): bool => $r->installed && $r->enabled));
    }

    /** Register a new plugin; throws if the name is already registered. */
    public function register(PluginRecord $record): void
    {
        if (isset($this->records[$record->name])) {
            throw new \RuntimeException("Plugin {$record->name} is already registered.");
        }
        $this->records[$record->name] = $record;
    }

    /** Overwrite an existing plugin's record; throws if the plugin is not registered. */
    public function save(PluginRecord $record): void
    {
        if (!isset($this->records[$record->name])) {
            throw new \RuntimeException("Plugin {$record->name} is not registered.");
        }
        $this->records[$record->name] = $record;
    }

    /** Flip a plugin's enabled flag; throws if the plugin is not registered. */
    public function setEnabled(string $name, bool $enabled): void
    {
        $current = $this->records[$name]
            ?? throw new \RuntimeException("Plugin {$name} is not registered.");

        $this->records[$name] = $current->withEnabled($enabled);
    }

    /** Remove a plugin from the registry; unknown name is a no-op. */
    public function unregister(string $name): void
    {
        unset($this->records[$name]);
    }

    /** Invalidate materialized activation caches; nothing to invalidate in-memory. */
    public function invalidateActivationCache(): void
    {
        // Nothing materialized to invalidate.
    }
}
