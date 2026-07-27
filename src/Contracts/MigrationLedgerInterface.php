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
 * The migration tracking store: which versions already ran per plugin.
 *
 * Read semantics: executedVersions()/executedMigrations() MUST NOT throw
 * when the backing store is unavailable — they return [] (matching the
 * host's historical behavior).
 */
interface MigrationLedgerInterface
{
    /** Make sure the ledger storage exists (create it when missing). */
    public function ensureStorage(): void;

    /** Record one executed migration. */
    public function recordExecuted(string $pluginName, string $version, ?string $description, \DateTimeImmutable $executedAt): void;

    /** Remove one executed record (rollback bookkeeping). Unknown pair: no-op. */
    public function removeExecuted(string $pluginName, string $version): void;

    /**
     * Versions already executed for a plugin, ordered by execution time ascending.
     *
     * @return list<string>
     */
    public function executedVersions(string $pluginName): array;

    /**
     * Full ledger rows for a plugin, ordered by execution time ascending.
     *
     * @return list<ExecutedMigration>
     */
    public function executedMigrations(string $pluginName): array;
}
