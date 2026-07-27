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

namespace Milpa\Plugin\Tests\Fixtures\MigFixture\Migrations;

use Milpa\Plugin\Contracts\MigrationContext;
use Milpa\Plugin\Contracts\PluginMigrationInterface;

/** Test fixture migration: records its runs on a static journal, no storage. */
class Version_1_0_0 implements PluginMigrationInterface
{
    /** @var list<string> */
    public static array $journal = [];

    public function version(): string
    {
        return '1.0.0';
    }

    public function description(): string
    {
        return 'first fixture migration';
    }

    public function up(MigrationContext $context): void
    {
        self::$journal[] = 'up:1.0.0';
    }

    public function down(MigrationContext $context): void
    {
        self::$journal[] = 'down:1.0.0';
    }
}
