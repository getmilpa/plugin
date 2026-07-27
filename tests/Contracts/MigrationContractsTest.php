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

namespace Milpa\Plugin\Tests\Contracts;

use Milpa\Plugin\Contracts\ExecutedMigration;
use Milpa\Plugin\Contracts\MigrationContext;
use Milpa\Plugin\Contracts\PluginMigrationInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The v2 migration contract is persistence-agnostic: a migration receives a
 * MigrationContext (connection handle + logger) instead of a Doctrine
 * EntityManager, so the package never depends on any ORM.
 */
final class MigrationContractsTest extends TestCase
{
    public function testMigrationContextExposesConnectionAndLogger(): void
    {
        $connection = new \stdClass();
        $logger = new NullLogger();

        $context = new MigrationContext($connection, $logger);

        $this->assertSame($connection, $context->connection);
        $this->assertSame($logger, $context->logger);
    }

    public function testExecutedMigrationCarriesTheLedgerRow(): void
    {
        $at = new \DateTimeImmutable('2026-07-20T12:00:00+00:00');
        $row = new ExecutedMigration('MailPlugin', '1.1.0', 'Add template index', $at);

        $this->assertSame('MailPlugin', $row->pluginName);
        $this->assertSame('1.1.0', $row->version);
        $this->assertSame('Add template index', $row->description);
        $this->assertSame($at, $row->executedAt);
    }

    public function testAConformingMigrationSatisfiesTheV2Contract(): void
    {
        $migration = new class () implements PluginMigrationInterface {
            public bool $upRan = false;

            public function version(): string
            {
                return '1.0.0';
            }

            public function description(): string
            {
                return 'creates the widgets table';
            }

            public function up(MigrationContext $context): void
            {
                $this->upRan = true;
            }

            public function down(MigrationContext $context): void
            {
            }
        };

        $migration->up(new MigrationContext(new \stdClass(), new NullLogger()));

        $this->assertTrue($migration->upRan);
        $this->assertSame('1.0.0', $migration->version());
    }
}
