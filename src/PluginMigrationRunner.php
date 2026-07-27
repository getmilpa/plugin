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

use Milpa\Plugin\Contracts\ExecutedMigration;
use Milpa\Plugin\Contracts\MigrationContext;
use Milpa\Plugin\Contracts\MigrationLedgerInterface;
use Milpa\Plugin\Contracts\PluginMigrationInterface;
use Milpa\ValueObjects\SemanticVersion;

/**
 * Discovers and executes plugin schema migrations (v2 contract).
 *
 * Migration classes live in the plugin's Migrations/ directory, named
 * Version_X_Y_Z.php, implement {@see PluginMigrationInterface}, and run in
 * semver order against the host-provided {@see MigrationContext}. Execution
 * is tracked through {@see MigrationLedgerInterface}. A discovered file
 * whose class does not conform to the contract is a LOUD error — never the
 * silent skip the legacy runner performed (the "invisible migration" bug).
 */
final class PluginMigrationRunner
{
    public function __construct(
        private readonly MigrationLedgerInterface $ledger,
        private readonly MigrationContext $context,
    ) {
    }

    /**
     * Run all pending migrations for a plugin.
     *
     * @return array{executed: int, migrations: array<array{version: string, description: string}>}
     */
    public function migrate(string $pluginName, string $migrationsDir, string $namespace): array
    {
        $this->ledger->ensureStorage();

        $pending = $this->getPending($pluginName, $migrationsDir, $namespace);

        $executed = [];
        foreach ($pending as $migration) {
            $migration->up($this->context);

            $this->ledger->recordExecuted(
                $pluginName,
                $migration->version(),
                $migration->description(),
                new \DateTimeImmutable(),
            );

            $executed[] = [
                'version' => $migration->version(),
                'description' => $migration->description(),
            ];
        }

        return [
            'executed' => count($executed),
            'migrations' => $executed,
        ];
    }

    /**
     * Rollback migrations to a target version (exclusive): every executed
     * migration with version > target reverts, newest first.
     *
     * @return array{reverted: int, migrations: array<array{version: string, description: string}>}
     */
    public function rollback(string $pluginName, string $migrationsDir, string $namespace, string $targetVersion): array
    {
        $this->ledger->ensureStorage();

        $target = SemanticVersion::parse($targetVersion);
        $allMigrations = $this->discoverMigrations($migrationsDir, $namespace);
        $executedVersions = $this->getExecutedVersions($pluginName);

        $toRevert = [];
        foreach ($allMigrations as $migration) {
            $migVersion = SemanticVersion::parse($migration->version());
            if ($migVersion->greaterThan($target) && in_array($migration->version(), $executedVersions, true)) {
                $toRevert[] = $migration;
            }
        }

        usort($toRevert, static function (PluginMigrationInterface $a, PluginMigrationInterface $b): int {
            return SemanticVersion::parse($b->version())->compareTo(SemanticVersion::parse($a->version()));
        });

        $reverted = [];
        foreach ($toRevert as $migration) {
            $migration->down($this->context);
            $this->ledger->removeExecuted($pluginName, $migration->version());

            $reverted[] = [
                'version' => $migration->version(),
                'description' => $migration->description(),
            ];
        }

        return [
            'reverted' => count($reverted),
            'migrations' => $reverted,
        ];
    }

    /**
     * Pending (not yet executed) migrations for a plugin, semver ascending.
     *
     * @return array<PluginMigrationInterface>
     */
    public function getPending(string $pluginName, string $migrationsDir, string $namespace): array
    {
        $this->ledger->ensureStorage();

        $allMigrations = $this->discoverMigrations($migrationsDir, $namespace);
        $executedVersions = $this->getExecutedVersions($pluginName);

        $pending = array_filter(
            $allMigrations,
            static fn (PluginMigrationInterface $m): bool => !in_array($m->version(), $executedVersions, true),
        );

        return array_values($pending);
    }

    /**
     * Versions already executed for a plugin, execution order ascending.
     *
     * @return array<string>
     */
    public function getExecutedVersions(string $pluginName): array
    {
        $this->ledger->ensureStorage();

        return $this->ledger->executedVersions($pluginName);
    }

    /**
     * Full ledger rows for a plugin, execution order ascending.
     *
     * @return list<ExecutedMigration>
     */
    public function getExecutedMigrations(string $pluginName): array
    {
        $this->ledger->ensureStorage();

        return $this->ledger->executedMigrations($pluginName);
    }

    /**
     * Discover migration classes from a Migrations/ directory, semver ascending.
     *
     * @return array<PluginMigrationInterface>
     *
     * @throws \RuntimeException When a Version_*.php file does not yield a
     *                           class conforming to the v2 contract — loud,
     *                           never a silent skip.
     */
    private function discoverMigrations(string $migrationsDir, string $namespace): array
    {
        if (!is_dir($migrationsDir)) {
            return [];
        }

        $migrations = [];
        $files = glob($migrationsDir . '/Version_*.php') ?: [];

        foreach ($files as $file) {
            $filename = pathinfo($file, PATHINFO_FILENAME);
            $fqcn = rtrim($namespace, '\\') . '\\Migrations\\' . $filename;

            if (!class_exists($fqcn)) {
                require_once $file;
            }

            if (!class_exists($fqcn)) {
                throw new \RuntimeException(
                    "Migration file {$file} does not declare the expected class {$fqcn} — every Version_*.php must be loadable under its plugin's Migrations namespace."
                );
            }

            $instance = new $fqcn();
            if (!$instance instanceof PluginMigrationInterface) {
                throw new \RuntimeException(
                    "Migration class {$fqcn} does not implement " . PluginMigrationInterface::class . ' — the legacy runner silently skipped these (the invisible-migration bug); the v2 runner refuses them loudly.'
                );
            }

            $migrations[] = $instance;
        }

        usort($migrations, static function (PluginMigrationInterface $a, PluginMigrationInterface $b): int {
            return SemanticVersion::parse($a->version())->compareTo(SemanticVersion::parse($b->version()));
        });

        return $migrations;
    }
}
