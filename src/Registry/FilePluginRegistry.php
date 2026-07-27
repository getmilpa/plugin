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
 * JSON-file-backed registry: the config-driven activation source. A missing
 * file is an empty registry; corrupt JSON is a loud error (never a silent
 * empty). Every mutation persists immediately.
 */
final class FilePluginRegistry implements PluginRegistryInterface
{
    public function __construct(
        private readonly string $filePath,
    ) {
    }

    /** Names of all enabled plugins; never throws — a broken store reads as []. */
    public function enabledNames(): array
    {
        try {
            $records = $this->load();
        } catch (\Throwable) {
            // The one boot-path read degrades on a broken store (see the
            // interface contract); every other operation stays loud.
            return [];
        }

        $names = [];
        foreach ($records as $record) {
            if ($record->enabled) {
                $names[] = $record->name;
            }
        }

        return $names;
    }

    /** Find one plugin's record by name, or null when not registered. */
    public function find(string $name): ?PluginRecord
    {
        return $this->load()[$name] ?? null;
    }

    /** All installed plugins. */
    public function installed(): array
    {
        return array_values(array_filter($this->load(), fn (PluginRecord $r): bool => $r->installed));
    }

    /** All plugins that are both installed and enabled. */
    public function installedAndEnabled(): array
    {
        return array_values(array_filter($this->load(), fn (PluginRecord $r): bool => $r->installed && $r->enabled));
    }

    /** Register a new plugin; throws if the name is already registered. */
    public function register(PluginRecord $record): void
    {
        $records = $this->load();
        if (isset($records[$record->name])) {
            throw new \RuntimeException("Plugin {$record->name} is already registered.");
        }
        $records[$record->name] = $record;
        $this->persist($records);
    }

    /** Overwrite an existing plugin's record; throws if the plugin is not registered. */
    public function save(PluginRecord $record): void
    {
        $records = $this->load();
        if (!isset($records[$record->name])) {
            throw new \RuntimeException("Plugin {$record->name} is not registered.");
        }
        $records[$record->name] = $record;
        $this->persist($records);
    }

    /** Flip a plugin's enabled flag; throws if the plugin is not registered. */
    public function setEnabled(string $name, bool $enabled): void
    {
        $records = $this->load();
        $current = $records[$name]
            ?? throw new \RuntimeException("Plugin {$name} is not registered.");

        $records[$name] = $current->withEnabled($enabled);
        $this->persist($records);
    }

    /** Remove a plugin from the registry; unknown name is a no-op. */
    public function unregister(string $name): void
    {
        $records = $this->load();
        if (!isset($records[$name])) {
            return;
        }
        unset($records[$name]);
        $this->persist($records);
    }

    /** Invalidate materialized activation caches; the file IS the source of truth. */
    public function invalidateActivationCache(): void
    {
        // The file IS the source of truth; nothing materialized to invalidate.
    }

    /**
     * @return array<string, PluginRecord> keyed by plugin name
     */
    private function load(): array
    {
        if (!file_exists($this->filePath)) {
            return [];
        }

        $raw = file_get_contents($this->filePath);
        if ($raw === false) {
            throw new \RuntimeException("Cannot read plugin registry file {$this->filePath}.");
        }

        try {
            /** @var array{plugins?: list<array<string, mixed>>} $data */
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException("Plugin registry file {$this->filePath} is not valid JSON: {$e->getMessage()}", 0, $e);
        }

        $records = [];
        foreach ($data['plugins'] ?? [] as $row) {
            $record = $this->fromRow($row);
            $records[$record->name] = $record;
        }

        return $records;
    }

    /**
     * @param array<string, PluginRecord> $records
     */
    private function persist(array $records): void
    {
        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'name' => $record->name,
                'version' => $record->version,
                'author' => $record->author,
                'site' => $record->site,
                'type' => $record->type,
                'installed' => $record->installed,
                'enabled' => $record->enabled,
                'source' => $record->source,
                'installedVersion' => $record->installedVersion,
                'installedAt' => $record->installedAt?->format(\DateTimeInterface::ATOM),
                'composerDeps' => $record->composerDeps,
            ];
        }

        $json = json_encode(['plugins' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($this->filePath, $json, LOCK_EX) === false) {
            throw new \RuntimeException("Cannot write plugin registry file {$this->filePath}.");
        }
    }

    /**
     * @param array<string, mixed> $row
     */
    private function fromRow(array $row): PluginRecord
    {
        return new PluginRecord(
            name: (string) $row['name'],
            version: (string) $row['version'],
            author: (string) $row['author'],
            site: (string) $row['site'],
            type: (string) $row['type'],
            installed: (bool) $row['installed'],
            enabled: (bool) $row['enabled'],
            source: isset($row['source']) ? (string) $row['source'] : null,
            installedVersion: isset($row['installedVersion']) ? (string) $row['installedVersion'] : null,
            installedAt: isset($row['installedAt']) ? new \DateTimeImmutable((string) $row['installedAt']) : null,
            composerDeps: isset($row['composerDeps']) && is_array($row['composerDeps'])
                ? array_values(array_map(strval(...), $row['composerDeps']))
                : null,
        );
    }
}
