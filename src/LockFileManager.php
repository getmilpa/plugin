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

/**
 * Manages the milpa.lock file.
 *
 * The lock file records the exact state of installed plugins:
 * - Which plugins are installed
 * - Their exact versions
 * - Their source (local or github:owner/repo)
 * - Installation timestamp
 * - Content hash for integrity checks
 *
 * Location: {rootPath}/milpa.lock
 */
final class LockFileManager
{
    private string $lockFilePath;

    public function __construct(string $rootPath)
    {
        $this->lockFilePath = rtrim($rootPath, '/') . '/milpa.lock';
    }

    /**
     * Generate or update the lock file from current installed plugins.
     *
     * @param array<array{name: string, version: string, source?: ?string, installedAt?: ?string, composerDeps?: ?array<string>}> $plugins
     */
    public function generate(array $plugins, string $milpaVersion = '2.0.0'): void
    {
        $pluginsData = [];

        foreach ($plugins as $plugin) {
            $name = $plugin['name'];
            $pluginsData[$name] = [
                'version' => $plugin['version'],
                'source' => $plugin['source'] ?? 'local',
                'installed-at' => $plugin['installedAt'] ?? (new \DateTime())->format('c'),
            ];

            if (!empty($plugin['composerDeps'])) {
                $pluginsData[$name]['composer-deps'] = $plugin['composerDeps'];
            }
        }

        ksort($pluginsData);

        $lock = [
            'milpa-version' => $milpaVersion,
            'generated-at' => (new \DateTime())->format('c'),
            'content-hash' => $this->computeHash($pluginsData),
            'plugins' => $pluginsData,
        ];

        $json = json_encode($lock, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($this->lockFilePath, $json);
    }

    /**
     * Read the lock file. Returns null if it doesn't exist.
     *
     * @return array{milpa-version: string, generated-at: string, content-hash?: string, plugins?: array<string, array{version: string, source: string, installed-at: string, composer-deps?: array<string>}>}|null
     */
    public function read(): ?array
    {
        if (!file_exists($this->lockFilePath)) {
            return null;
        }

        $json = file_get_contents($this->lockFilePath);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Verify the lock file integrity by recomputing the content hash.
     */
    public function verify(): bool
    {
        $lock = $this->read();
        if ($lock === null) {
            return false;
        }

        $expectedHash = $lock['content-hash'] ?? '';
        $actualHash = $this->computeHash($lock['plugins'] ?? []);

        return hash_equals($expectedHash, $actualHash);
    }

    /**
     * Check if the lock file exists.
     */
    public function exists(): bool
    {
        return file_exists($this->lockFilePath);
    }

    /**
     * Get the lock file path.
     */
    public function getPath(): string
    {
        return $this->lockFilePath;
    }

    /**
     * Get installed plugin info from the lock file.
     *
     * @return array{version: string, source: string, installed-at: string, composer-deps?: array<string>}|null
     */
    public function getPluginLock(string $pluginName): ?array
    {
        $lock = $this->read();
        if ($lock === null) {
            return null;
        }

        return $lock['plugins'][$pluginName] ?? null;
    }

    /**
     * Compute a SHA-256 hash of the plugins data for integrity checking.
     *
     * @param array<string, array{version: string, source: string, installed-at: string, composer-deps?: array<string>}> $pluginsData
     */
    private function computeHash(array $pluginsData): string
    {
        $serialized = json_encode($pluginsData, JSON_THROW_ON_ERROR);
        return hash('sha256', $serialized);
    }
}
