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

namespace Milpa\Plugin\Tests;

use Milpa\Plugin\Contracts\ExecutedMigration;
use Milpa\Plugin\Contracts\MigrationContext;
use Milpa\Plugin\Contracts\MigrationLedgerInterface;
use Milpa\Plugin\PluginMigrationRunner;
use Milpa\Plugin\Tests\Fixtures\MigFixture\Migrations\Version_1_0_0;
use Milpa\Plugin\Tests\Fixtures\MigFixture\Migrations\Version_1_1_0;
use Milpa\Plugin\Tests\Fixtures\MigFixture\Migrations\Version_1_2_0;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * PluginMigrationRunner (v2, Ola 6c): ledger tracking through the
 * MigrationLedgerInterface port instead of touching Doctrine directly, and
 * a discovered Version_*.php file that does not conform to
 * PluginMigrationInterface is now a LOUD \RuntimeException — never the
 * legacy runner's silent skip (the "invisible migration" bug, flipped).
 */
final class PluginMigrationRunnerTest extends TestCase
{
    private const PLUGIN = 'MigFixture';
    private const NAMESPACE_ = 'Milpa\\Plugin\\Tests\\Fixtures\\MigFixture';

    private MigrationLedgerInterface $ledger;
    private PluginMigrationRunner $runner;
    private string $migrationsDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->migrationsDir = __DIR__ . '/Fixtures/migrations';

        // The fixture directory intentionally does NOT follow PSR-4 (mirrors
        // real plugin Migrations/ dirs, which live outside any autoload
        // map) — load the classes ourselves before touching their statics;
        // the runner's own discoverMigrations() guards with class_exists()
        // and will not re-require them.
        require_once $this->migrationsDir . '/Version_1_0_0.php';
        require_once $this->migrationsDir . '/Version_1_1_0.php';
        require_once $this->migrationsDir . '/Version_1_2_0.php';

        Version_1_0_0::$journal = [];
        Version_1_1_0::$journal = [];
        Version_1_2_0::$journal = [];

        // In-memory ledger over an array: implements the full port. Reads
        // filter by plugin but preserve recording sequence — no re-sort.
        $this->ledger = new class () implements MigrationLedgerInterface {
            /** @var list<ExecutedMigration> */
            private array $rows = [];

            public function ensureStorage(): void
            {
            }

            public function recordExecuted(string $pluginName, string $version, ?string $description, \DateTimeImmutable $executedAt): void
            {
                $this->rows[] = new ExecutedMigration($pluginName, $version, $description, $executedAt);
            }

            public function removeExecuted(string $pluginName, string $version): void
            {
                foreach ($this->rows as $i => $row) {
                    if ($row->pluginName === $pluginName && $row->version === $version) {
                        unset($this->rows[$i]);
                        break;
                    }
                }
                $this->rows = array_values($this->rows);
            }

            public function executedVersions(string $pluginName): array
            {
                return array_values(array_map(
                    static fn (ExecutedMigration $r): string => $r->version,
                    array_filter($this->rows, static fn (ExecutedMigration $r): bool => $r->pluginName === $pluginName),
                ));
            }

            public function executedMigrations(string $pluginName): array
            {
                return array_values(array_filter(
                    $this->rows,
                    static fn (ExecutedMigration $r): bool => $r->pluginName === $pluginName,
                ));
            }
        };

        $context = new MigrationContext(new \stdClass(), new NullLogger());
        $this->runner = new PluginMigrationRunner($this->ledger, $context);
    }

    public function testMigrateExecutesInSemverOrderAndRecordsTheLedger(): void
    {
        $result = $this->runner->migrate(self::PLUGIN, $this->migrationsDir, self::NAMESPACE_);

        $this->assertSame(3, $result['executed']);
        $this->assertSame(['1.0.0', '1.1.0', '1.2.0'], array_column($result['migrations'], 'version'));
        $this->assertSame(
            ['up:1.0.0', 'up:1.1.0', 'up:1.2.0'],
            array_merge(Version_1_0_0::$journal, Version_1_1_0::$journal, Version_1_2_0::$journal),
        );
        $this->assertSame(['1.0.0', '1.1.0', '1.2.0'], $this->runner->getExecutedVersions(self::PLUGIN));
    }

    public function testASecondMigrateRunsNothing(): void
    {
        $this->runner->migrate(self::PLUGIN, $this->migrationsDir, self::NAMESPACE_);
        $result = $this->runner->migrate(self::PLUGIN, $this->migrationsDir, self::NAMESPACE_);

        $this->assertSame(0, $result['executed']);
        $this->assertSame([], $result['migrations']);
    }

    public function testRollbackToTargetIsExclusiveAndDescending(): void
    {
        $this->runner->migrate(self::PLUGIN, $this->migrationsDir, self::NAMESPACE_);
        // Reset: isolate the rollback's own down:* entries from migrate's up:* ones.
        Version_1_1_0::$journal = [];
        Version_1_2_0::$journal = [];

        $result = $this->runner->rollback(self::PLUGIN, $this->migrationsDir, self::NAMESPACE_, '1.0.0');

        // Exclusive target: 1.0.0 stays. Descending revert: 1.2.0 BEFORE 1.1.0.
        $this->assertSame(2, $result['reverted']);
        $this->assertSame(['1.2.0', '1.1.0'], array_column($result['migrations'], 'version'));
        $this->assertSame(
            ['down:1.2.0', 'down:1.1.0'],
            array_merge(Version_1_2_0::$journal, Version_1_1_0::$journal),
        );
        $this->assertSame(['1.0.0'], $this->runner->getExecutedVersions(self::PLUGIN));
    }

    public function testANonConformingMigrationFileThrowsNamingTheClassAndTheContract(): void
    {
        // The invisible-migration bug, flipped: 6c refuses loudly instead of
        // silently discarding. Separate dir/namespace so this fixture never
        // touches the other tests.
        $brokenDir = __DIR__ . '/Fixtures/migrations-broken';
        $brokenNamespace = 'Milpa\\Plugin\\Tests\\Fixtures\\MigFixtureBroken';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Version_Broken.*PluginMigrationInterface/s');

        $this->runner->getPending('BrokenPlugin', $brokenDir, $brokenNamespace);
    }

    public function testGetExecutedMigrationsReturnsExecutedMigrationValueObjects(): void
    {
        $this->runner->migrate(self::PLUGIN, $this->migrationsDir, self::NAMESPACE_);

        $rows = $this->runner->getExecutedMigrations(self::PLUGIN);

        $this->assertCount(3, $rows);
        foreach ($rows as $row) {
            $this->assertInstanceOf(ExecutedMigration::class, $row);
        }
        $this->assertSame(['1.0.0', '1.1.0', '1.2.0'], array_map(static fn (ExecutedMigration $r): string => $r->version, $rows));
        $this->assertSame('first fixture migration', $rows[0]->description);
        $this->assertInstanceOf(\DateTimeImmutable::class, $rows[0]->executedAt);
    }
}
