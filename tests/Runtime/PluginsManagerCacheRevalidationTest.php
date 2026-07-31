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

namespace Milpa\Plugin\Tests\Runtime;

use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Plugin\Registry\InMemoryPluginRegistry;
use Milpa\Plugin\Runtime\ManagerConfig;
use Milpa\Plugin\Runtime\PluginsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * The §5 blind-spot: the plugin-cache hit path used to boot whatever the cache said without
 * re-validating the architecture. These tests pin the closed behavior:
 *
 * - a valid cached graph boots from the cache exactly as before;
 * - a cached graph the resolver reports as `blocked` (here: a stale `requires` no plugin provides)
 *   logs the learnable warning and FALLS BACK to the full scan path, which reboots from disk truth
 *   and rewrites the cache (self-healing, never a hard crash from a stale cache);
 * - without a readable root `milpa.json` hostProfile the gate does not apply (no invented default
 *   profile may block a boot that works today).
 *
 * Ported (Ola 6b T5) from the host's `PluginsCacheRevalidationTest` — the per-process
 * `rootPath`/`DS` constant pair is gone: {@see PluginsManager} takes its config injected via
 * {@see ManagerConfig}, so every test in this file runs in ONE shared process.
 */
final class PluginsManagerCacheRevalidationTest extends TestCase
{
    private const FIXTURE_CLASS = 'Milpa\\Plugins\\CacheFixturePlugin\\CacheFixturePlugin';

    private string $tmp;

    private DIContainerInterface $container;

    /** @var list<array{level: string, message: string}> */
    private array $logRecords = [];

    private PluginsManager $plugins;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'milpa-cache-revalidation-' . uniqid();
        mkdir($this->tmp . '/storage/cache', 0777, true);
        mkdir($this->tmp . '/plugins/CacheFixturePlugin', 0777, true);

        // The fixture plugin on disk: its REAL metadata is clean (no requires) — disk truth.
        file_put_contents($this->tmp . '/plugins/CacheFixturePlugin/CacheFixturePlugin.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Milpa\Plugins\CacheFixturePlugin;

            use Milpa\Attributes\PluginMetadata;

            #[PluginMetadata(
                version: '1.0.0',
                author: 'Acme',
                site: 'https://teamx.agency',
                name: 'CacheFixture',
                type: 'Service'
            )]
            class CacheFixturePlugin
            {
                public function __construct(private mixed $container)
                {
                }
            }
            PHP);

        // setUp runs once PER TEST in this one-process suite (3 tests), each writing to a fresh
        // $this->tmp — but the FQCN is fixed, so guard the require against a fatal redeclaration
        // on the 2nd/3rd test. Content is byte-identical every time, so skipping is safe.
        if (!class_exists(self::FIXTURE_CLASS, false)) {
            require_once $this->tmp . '/plugins/CacheFixturePlugin/CacheFixturePlugin.php';
        }

        // Enabled-plugins cache present so loadPlugins() never touches the database.
        file_put_contents(
            $this->tmp . '/storage/cache/enabled_plugins.php',
            "<?php\nreturn " . var_export(['CacheFixture'], true) . ";\n"
        );

        $logRecords = &$this->logRecords;
        $logger = new class ($logRecords) extends AbstractLogger {
            /** @param list<array{level: string, message: string}> $records */
            public function __construct(private array &$records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };

        $this->container = $this->createMock(DIContainerInterface::class);
        $this->container->method('get')->willReturnCallback(
            static fn ($id) => $id === LoggerInterface::class ? $logger : null
        );
        $this->container->method('has')->willReturn(false);

        $this->plugins = new PluginsManager(
            $this->container,
            new InMemoryPluginRegistry(),
            new ManagerConfig(
                cacheDir: $this->tmp . '/storage/cache',
                hostManifestPath: $this->tmp . '/milpa.json',
                devMode: false,
                environment: 'CLI',
            ),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
        parent::tearDown();
    }

    public function testValidCachedGraphBootsFromCache(): void
    {
        $this->writeHostProfile();
        $this->writePluginsCache([$this->cachedMetadata(requires: [])]);

        // Deliberately NO plugin path registered: if the cache were rejected, the fallback scan
        // would find nothing and getPluginsMetadata() would stay empty.
        $this->plugins->loadPlugins();

        $metadata = $this->plugins->getPluginsMetadata();
        $this->assertCount(1, $metadata);
        $this->assertSame('CacheFixture', $metadata[0]['name']);
        $this->assertSame([], $this->warningsContaining('blocked'));
    }

    public function testBlockedCachedGraphFallsBackAndRebuildsTheCache(): void
    {
        $this->writeHostProfile();
        // Stale cache: it claims a hard dependency no plugin provides — the resolver must block it.
        $this->writePluginsCache([$this->cachedMetadata(requires: ['Milpa\\Fixtures\\GhostInterface'])]);
        $this->plugins->addPluginPath($this->tmp . '/plugins');

        $this->plugins->loadPlugins();

        // The learnable warning: code + message + fix + learn link, then the fallback.
        $warnings = $this->warningsContaining('Cached plugin graph is blocked');
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('MILPA_CAPABILITY_MISSING', $warnings[0]);
        $this->assertStringContainsString('Fix:', $warnings[0]);
        $this->assertStringContainsString('Learn:', $warnings[0]);
        $this->assertStringContainsString('Falling back to a full plugin scan', $warnings[0]);

        // The fallback booted from disk truth (clean metadata), not from the poisoned cache.
        $metadata = $this->plugins->getPluginsMetadata();
        $this->assertCount(1, $metadata);
        $this->assertSame('CacheFixture', $metadata[0]['name']);
        $this->assertSame([], $metadata[0]['requires']);

        // Self-healing: the cache was rewritten from the fresh scan and no longer carries the
        // unsatisfiable requirement.
        $rebuilt = (string) file_get_contents($this->tmp . '/storage/cache/plugins.php');
        $this->assertStringNotContainsString('GhostInterface', $rebuilt);
        $this->assertStringContainsString('CacheFixture', $rebuilt);
    }

    public function testWithoutHostProfileTheCacheBootsUngatedAsBefore(): void
    {
        // No milpa.json at the root: the resolver gate must NOT apply — no invented default
        // profile may block a boot that works today.
        $this->writePluginsCache([$this->cachedMetadata(requires: ['Milpa\\Fixtures\\GhostInterface'])]);

        $this->plugins->loadPlugins();

        $metadata = $this->plugins->getPluginsMetadata();
        $this->assertCount(1, $metadata);
        $this->assertSame(['Milpa\\Fixtures\\GhostInterface'], $metadata[0]['requires']);
        $this->assertSame([], $this->warningsContaining('blocked'));
    }

    /**
     * The tmp-root profile models the REAL root `milpa.json` — since Cosecha T4 that means the
     * tightened `allowedLegacyContracts: []`. Cached metadata re-validates through the attribute
     * ingestion (never legacy-shaped), so the closed door does not change these verdicts.
     */
    private function writeHostProfile(): void
    {
        file_put_contents($this->tmp . '/milpa.json', (string) json_encode([
            'hostProfile' => [
                'name' => 'test-host',
                'version' => '1.0.0',
                'requiredContracts' => [],
                'requiredCapabilities' => [],
                'enabledSurfaces' => ['cli'],
                'allowedLegacyContracts' => [],
                'acceptedRisks' => [],
            ],
        ], JSON_PRETTY_PRINT));
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private function writePluginsCache(array $entries): void
    {
        file_put_contents(
            $this->tmp . '/storage/cache/plugins.php',
            "<?php\nreturn " . var_export($entries, true) . ";\n"
        );
    }

    /**
     * @param list<string> $requires
     *
     * @return array<string, mixed>
     */
    private function cachedMetadata(array $requires): array
    {
        return [
            'version' => '1.0.0',
            'author' => 'Acme',
            'site' => 'https://teamx.agency',
            'name' => 'CacheFixture',
            'type' => 'Service',
            'provides' => [],
            'requires' => $requires,
            'suggests' => [],
            'class' => self::FIXTURE_CLASS,
        ];
    }

    /**
     * @return list<string>
     */
    private function warningsContaining(string $needle): array
    {
        $out = [];
        foreach ($this->logRecords as $record) {
            if ($record['level'] === 'warning' && str_contains($record['message'], $needle)) {
                $out[] = $record['message'];
            }
        }

        return $out;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
