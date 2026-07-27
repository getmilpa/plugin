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
use Milpa\Plugin\ContractResolver;
use Milpa\Plugin\Registry\InMemoryPluginRegistry;
use Milpa\Plugin\Runtime\ManagerConfig;
use Milpa\Plugin\Runtime\PluginsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Orden slice, T4: the host's FRESH path (scan -> gate -> order -> boot) runs ONE milpa/resolver
 * resolution for both the gate and the order — the legacy `Milpa\Plugin\ContractResolver` stops
 * orchestrating the boot. These tests pin the swap:
 *
 * - on a multi-plugin dependency fixture the fresh path boots in EXACTLY the order the legacy
 *   `ContractResolver::getLoadOrder()` produced (T1's equivalence, proven at the host seam);
 * - a plugin whose hard `requires` no plugin provides still refuses to boot — now with the
 *   report's learnable first line (code + why + fix + Academy link) instead of a bare message,
 *   and it refuses even when the root `milpa.json` offers no hostProfile (the fresh path falls
 *   back to a permissive profile, it never skips the gate — the resolve is also what orders);
 * - a root `milpa.json` whose `hostProfile` block is malformed logs a notice (the gate being
 *   silently off was the Minor that traveled) — and the boot proceeds ungated, as before;
 * - `writeCache()` persists the report's order, so the cache-hit path (unchanged) boots that
 *   same order without re-sorting;
 * - an EMPTY enabled set has nothing to gate and nothing to order: the resolve is skipped, so a
 *   host profile with unmet `requiredCapabilities` cannot crash a boot that works today.
 *
 * Ported (Ola 6b T5) from the host's `PluginsFreshPathResolutionTest` — the per-process
 * `rootPath`/`DS` constant pair is gone: {@see PluginsManager} takes its config injected via
 * {@see ManagerConfig}, so every test in this file runs in ONE shared process with no static
 * state to reset between them (`Plugins::$plugins` had no replacement to reset — there is no
 * static). The `OrphanFixture` name collides with the legacy string-shaped fixture of the same
 * name in the fresh-path host suite's own migration target; here it stays legacy-shaped
 * (`requires: ['Milpa\Fixtures\GhostInterface']`) — see
 * {@see PluginsManagerAttributeAuthorityTest} for the canonical-record-shaped fixture, renamed
 * `CanonicalOrphanFixture` to avoid a same-FQCN/different-content collision in this shared
 * process.
 */
final class PluginsManagerFreshPathTest extends TestCase
{
    private string $tmp;

    private DIContainerInterface $container;

    /** @var list<array{level: string, message: string}> */
    private array $logRecords = [];

    private PluginsManager $plugins;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'milpa-fresh-resolution-' . uniqid();
        mkdir($this->tmp . '/storage/cache', 0777, true);
        mkdir($this->tmp . '/plugins', 0777, true);

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

        $this->plugins = $this->newManager();
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
        parent::tearDown();
    }

    private function newManager(): PluginsManager
    {
        return new PluginsManager(
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

    public function testFreshPathBootsInTheOrderContractResolverProduced(): void
    {
        $this->writeHostProfile();
        $this->writeDependencyFixture();

        // Capture the SCAN order (the resolver input order) and compute what the legacy
        // ContractResolver would have booted — the equivalence baseline. The probe instance
        // first runs loadPlugins() with NO path registered: that only loads the enabled-plugins
        // cache (scanPluginsPath() honours enablement), scans nothing and boots nothing.
        $probe = $this->newManager();
        $probe->loadPlugins();
        $probe->scanPluginsPath($this->tmp . '/plugins');
        $scanned = $probe->getPluginsMetadata();
        $this->assertCount(3, $scanned);
        $legacyOrder = array_column((new ContractResolver())->getLoadOrder($scanned), 'name');

        // Now the real fresh path, from zero.
        $this->plugins->addPluginPath($this->tmp . '/plugins');
        $this->plugins->loadPlugins();

        $freshOrder = array_column($this->plugins->getPluginsMetadata(), 'name');
        $this->assertSame($legacyOrder, $freshOrder, 'The report must boot the EXACT order the legacy ContractResolver produced.');

        // And that order is genuinely topological: provider before requirer, transitively.
        $this->assertLessThan(
            array_search('BetaFixture', $freshOrder, true),
            array_search('GammaFixture', $freshOrder, true),
            'GammaFixture provides what BetaFixture requires, so it must boot first.'
        );
        $this->assertLessThan(
            array_search('AlphaFixture', $freshOrder, true),
            array_search('BetaFixture', $freshOrder, true),
            'BetaFixture provides what AlphaFixture requires, so it must boot first.'
        );
    }

    public function testMissingRequireStillRefusesToBootWithALearnableMessage(): void
    {
        $this->writeHostProfile();
        $this->writePlugin('OrphanFixture', requires: ['Milpa\\Fixtures\\GhostInterface']);
        $this->enable(['OrphanFixture']);
        $this->plugins->addPluginPath($this->tmp . '/plugins');

        try {
            $this->plugins->loadPlugins();
            $this->fail('A hard requires no plugin provides must refuse to boot.');
        } catch (\RuntimeException $e) {
            // The message is the report's learnable first line, not a bare alarm.
            $this->assertStringContainsString('MILPA_CAPABILITY_MISSING', $e->getMessage());
            $this->assertStringContainsString('GhostInterface', $e->getMessage());
            $this->assertStringContainsString('Fix:', $e->getMessage());
            $this->assertStringContainsString('Learn:', $e->getMessage());
        }

        $errors = $this->recordsContaining('error', '[Plugins]');
        $this->assertNotSame([], $errors, 'The refusal must be logged in the [Plugins] style.');
        $this->assertStringContainsString('MILPA_CAPABILITY_MISSING', $errors[0]);
    }

    public function testMissingRequireRefusesToBootEvenWithoutAHostProfile(): void
    {
        // No root milpa.json at all: the fresh path must fall back to a permissive profile and
        // still gate the plugin-level requires — the old ContractResolver::validate() threw here
        // regardless of any profile, and that verdict must not soften.
        $this->writePlugin('OrphanFixture', requires: ['Milpa\\Fixtures\\GhostInterface']);
        $this->enable(['OrphanFixture']);
        $this->plugins->addPluginPath($this->tmp . '/plugins');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/MILPA_CAPABILITY_MISSING/');

        $this->plugins->loadPlugins();
    }

    public function testMalformedHostProfileLogsANoticeAndBootsUngated(): void
    {
        // The block exists but is not an object: today this silently turned the gate off.
        file_put_contents($this->tmp . '/milpa.json', (string) json_encode(['hostProfile' => 'not-an-object']));
        $this->writePlugin('CleanFixture', requires: []);
        $this->enable(['CleanFixture']);
        $this->plugins->addPluginPath($this->tmp . '/plugins');

        $this->plugins->loadPlugins();

        $this->assertCount(1, $this->plugins->getPluginsMetadata());
        $notices = $this->recordsContaining('notice', 'hostProfile');
        $this->assertCount(1, $notices, 'A malformed hostProfile block must be a visible notice, never silent.');
    }

    public function testAMissingProfileFileAndAMissingProfileKeyStaySilent(): void
    {
        // The silent side of the malformed-hostProfile notice: NO milpa.json at all, and a
        // milpa.json WITHOUT a hostProfile key, are both legitimate no-profile hosts — the boot
        // proceeds ungated with ZERO notice logs (only a DECLARED-but-broken block may notice).
        $this->writePlugin('CleanFixture', requires: []);
        $this->enable(['CleanFixture']);
        $this->plugins->addPluginPath($this->tmp . '/plugins');
        $this->plugins->loadPlugins();
        $this->assertCount(1, $this->plugins->getPluginsMetadata());

        // Second fresh boot, now with a milpa.json that simply carries no hostProfile block.
        file_put_contents($this->tmp . '/milpa.json', (string) json_encode(['name' => 'not-a-profile']));
        @unlink($this->tmp . '/storage/cache/plugins.php');
        $rerun = $this->newManager();
        $rerun->addPluginPath($this->tmp . '/plugins');
        $rerun->loadPlugins();
        $this->assertCount(1, $rerun->getPluginsMetadata());

        $notices = array_values(array_filter(
            $this->logRecords,
            static fn (array $record): bool => $record['level'] === 'notice'
        ));
        $this->assertSame([], $notices, 'A missing milpa.json or a missing hostProfile key must stay silent — no notice.');
    }

    public function testWriteCachePersistsTheReportOrder(): void
    {
        $this->writeHostProfile();
        $this->writeDependencyFixture();
        $this->plugins->addPluginPath($this->tmp . '/plugins');

        $this->plugins->loadPlugins();

        $cacheFile = $this->tmp . '/storage/cache/plugins.php';
        $this->assertFileExists($cacheFile);
        /** @var array<int, array<string, mixed>> $cached */
        $cached = require $cacheFile;

        $this->assertSame(
            array_column($this->plugins->getPluginsMetadata(), 'name'),
            array_column($cached, 'name'),
            'The cache must persist the report order, so the cache-hit path boots it without re-sorting.'
        );
    }

    public function testEmptyEnabledSetBootsUngatedEvenWhenTheProfileDemandsCapabilities(): void
    {
        // The host profile demands a capability nobody provides — but with an EMPTY enabled set
        // there is nothing to gate and nothing to order: the boot that works today keeps working.
        $this->writeHostProfile(requiredCapabilities: ['Milpa\\Fixtures\\GhostInterface']);
        $this->writePlugin('CleanFixture', requires: []);
        $this->enable([]);
        $this->plugins->addPluginPath($this->tmp . '/plugins');

        $this->plugins->loadPlugins();

        $this->assertSame([], $this->plugins->getPluginsMetadata());
        $this->assertSame([], $this->recordsContaining('error', '[Plugins]'));
    }

    /**
     * Three plugins whose scan (alphabetical) order INVERTS the boot order:
     * Alpha requires what Beta provides, Beta requires what Gamma provides.
     */
    private function writeDependencyFixture(): void
    {
        $this->writePlugin('AlphaFixture', requires: ['Milpa\\Fixtures\\BetaContract']);
        $this->writePlugin('BetaFixture', provides: ['Milpa\\Fixtures\\BetaContract'], requires: ['Milpa\\Fixtures\\GammaContract']);
        $this->writePlugin('GammaFixture', provides: ['Milpa\\Fixtures\\GammaContract']);
        $this->enable(['AlphaFixture', 'BetaFixture', 'GammaFixture']);
    }

    /**
     * @param list<string> $provides
     * @param list<string> $requires
     */
    private function writePlugin(string $name, array $provides = [], array $requires = []): void
    {
        $dir = $this->tmp . '/plugins/' . $name . 'Plugin';
        mkdir($dir, 0777, true);

        $providesPhp = var_export($provides, true);
        $requiresPhp = var_export($requires, true);

        $file = $dir . '/' . $name . 'Plugin.php';
        file_put_contents($file, <<<PHP
            <?php

            declare(strict_types=1);

            namespace Milpa\\Plugins\\{$name}Plugin;

            use Milpa\\Attributes\\PluginMetadata;

            #[PluginMetadata(
                version: '1.0.0',
                author: 'TeamX',
                site: 'https://teamx.agency',
                name: '{$name}',
                type: 'Service',
                provides: {$providesPhp},
                requires: {$requiresPhp}
            )]
            class {$name}Plugin
            {
                public function __construct(private mixed \$container)
                {
                }
            }
            PHP);

        // The same fixture NAME writes to a fresh $this->tmp dir on every call across every
        // test in this ONE-process suite (no more #[RunTestsInSeparateProcesses]) — but the
        // FQCN is derived only from $name, so a second require_once of the same class would be
        // a fatal redeclaration. Every call site in this file writes byte-identical content for
        // a given $name, so skipping the require once the class is already loaded is safe.
        $fqcn = "Milpa\\Plugins\\{$name}Plugin\\{$name}Plugin";
        if (!class_exists($fqcn, false)) {
            require_once $file;
        }
    }

    /**
     * @param list<string> $names
     */
    private function enable(array $names): void
    {
        file_put_contents(
            $this->tmp . '/storage/cache/enabled_plugins.php',
            "<?php\nreturn " . var_export($names, true) . ";\n"
        );
    }

    /**
     * The tmp-root profile models the REAL root `milpa.json` — since Cosecha T4 that means the
     * tightened `allowedLegacyContracts: []`. The fixtures here are attribute-declared (the boot
     * path ingests via {@see \Milpa\Resolver\Ingest\AttributeLoader}, never legacy-shaped), so the
     * closed door does not — and must not — change these verdicts.
     *
     * @param list<string> $requiredCapabilities
     */
    private function writeHostProfile(array $requiredCapabilities = []): void
    {
        file_put_contents($this->tmp . '/milpa.json', (string) json_encode([
            'hostProfile' => [
                'name' => 'test-host',
                'version' => '1.0.0',
                'requiredContracts' => [],
                'requiredCapabilities' => $requiredCapabilities,
                'enabledSurfaces' => ['cli'],
                'allowedLegacyContracts' => [],
                'acceptedRisks' => [],
            ],
        ], JSON_PRETTY_PRINT));
    }

    /**
     * @return list<string>
     */
    private function recordsContaining(string $level, string $needle): array
    {
        $out = [];
        foreach ($this->logRecords as $record) {
            if ($record['level'] === $level && str_contains($record['message'], $needle)) {
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
