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
 * Contract for one plugin schema migration (v2 — persistence-agnostic).
 *
 * Migration classes live in the plugin's Migrations/ directory, named
 * Version_X_Y_Z.php, and are executed in semver order. A discovered file
 * whose class does NOT conform to this contract is a loud error, never a
 * silent skip.
 */
interface PluginMigrationInterface
{
    /** The semver this migration belongs to, e.g. "1.1.0". */
    public function version(): string;

    /** Human-readable summary of what the migration does. */
    public function description(): string;

    /** Apply the migration (forward). */
    public function up(MigrationContext $context): void;

    /** Revert the migration. Must undo exactly what up() did. */
    public function down(MigrationContext $context): void;
}
