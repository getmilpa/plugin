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
use Milpa\Resolver\Manifest\HostProfile;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * D5 re-specification (Ola 6b T5) of the host's `PluginsCanonicalManifestBootTest`: the ported
 * {@see PluginsManager::getMetadata()} reads plugin identity from the `#[PluginMetadata]`
 * attribute ONLY — it never opens a plugin's own `milpa.json` (that preference lived in the
 * legacy host `Plugins::getMetadata()`, which favored {@see \Milpa\Plugin\PluginManifest}). A
 * plugin's `milpa.json` remains the distribution manifest on disk; divergence between the two
 * now surfaces as a doctor parity warning elsewhere, never as a different boot graph. These
 * tests pin the NEW authority:
 *
 * - the boot reads the ATTRIBUTE even when the plugin's own manifest declares different data
 *   (here: a divergent `version`) — the manifest is never consulted for boot metadata;
 * - a rich (record-shaped) `requires` entry the graph cannot close still refuses to boot with
 *   the report's learnable first line — attribute-declared records resolve exactly like
 *   canonical manifest records did before (`CapabilityRequirement::parse()` is the same seam);
 * - the cache round-trip (`writeCache()` -> `loadFromCache()` re-validation) carries the
 *   record-shaped `provides`/`requires` intact — the cached shape now originates from the
 *   attribute instead of the manifest, but the re-validation path is unchanged;
 * - a plugin's own `milpa.json` that is not even valid JSON does not affect the boot at all —
 *   the manager never reads it, so a corrupt manifest is inert.
 *
 * Ported (Ola 6b T5) from the host's `PluginsCanonicalManifestBootTest` — the per-process
 * `rootPath`/`DS` constant pair is gone: {@see PluginsManager} takes its config injected via
 * {@see ManagerConfig}, so every test in this file runs in ONE shared process. The
 * `writeCanonicalPlugin()` harness ports with two added optional parameters
 * (`$attributeVersion`/`$manifestVersion`) so the new authority test can make the two diverge —
 * every other call site keeps the old '1.0.0'/'1.0.0' default, so this is not a behavior change
 * for the ported tests. `OrphanFixture` (record-shaped `requires`) is renamed here to
 * `CanonicalOrphanFixture`: the fresh-path host suite ({@see PluginsManagerFreshPathTest}) already
 * uses the bare name for a legacy string-shaped fixture, and both suites now share one PHPUnit
 * process — same FQCN with different `#[PluginMetadata]` content would fatally redeclare.
 */
final class PluginsManagerAttributeAuthorityTest extends TestCase
{
    private string $tmp;

    private DIContainerInterface $container;

    /** @var list<array{level: string, message: string}> */
    private array $logRecords = [];

    private PluginsManager $plugins;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'milpa-attribute-authority-' . uniqid();
        mkdir($this->tmp . '/storage/cache', 0777, true);
        mkdir($this->tmp . '/plugins', 0777, true);

        // The tmp-root host profile: it requires the capability the canonical fixture provides,
        // so `loadHostProfile()` returns a real profile and the cache-hit path MUST re-validate
        // (`cachedGraphIsBootable()` no longer short-circuits at `hostProfile === null`).
        $this->writeHostProfile(requiredCapabilities: ['crm.fixture.alpha.v1@^1.0']);

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

    /**
     * D5, directly: the manager's `getMetadata()` no longer prefers a plugin's own `milpa.json` —
     * it reads the `#[PluginMetadata]` attribute, full stop. A fixture whose manifest declares
     * `version: '9.9.9'` while the attribute says `1.0.0` must boot with the ATTRIBUTE's version.
     */
    public function testBootReadsTheAttributeEvenWhenTheManifestDeclaresADifferentVersion(): void
    {
        $this->writeCanonicalPlugin(
            'ProviderFixture',
            provides: [[
                'id' => 'crm.fixture.alpha.v1',
                'interface' => 'Milpa\\Fixtures\\AlphaInterface',
                'contractVersion' => '1.0.0',
                'service' => 'Milpa\\Fixtures\\AlphaService',
            ]],
            attributeVersion: '1.0.0',
            manifestVersion: '9.9.9',
        );
        $this->enable(['ProviderFixture']);
        $this->plugins->addPluginPath($this->tmp . '/plugins');

        $this->plugins->loadPlugins();

        $metadata = $this->plugins->getPluginsMetadata();
        $this->assertCount(1, $metadata);
        $this->assertSame(
            '1.0.0',
            $metadata[0]['version'],
            "the boot must read the #[PluginMetadata] attribute's version, never the plugin's own milpa.json — even when the two diverge."
        );
        // The provides record itself also flowed from the attribute, not the manifest.
        $this->assertIsArray($metadata[0]['provides'][0]);
        $this->assertSame('crm.fixture.alpha.v1', $metadata[0]['provides'][0]['id']);
    }

    public function testARichRequireNobodyProvidesRefusesToBootWithTheLearnableLine(): void
    {
        // ProviderFixture closes the tmp-root profile's own `crm.fixture.alpha.v1@^1.0` demand,
        // so the ONLY missing entry — and therefore the report's first learnable line — is the
        // orphaned rich require this test is about.
        $this->writeCanonicalPlugin(
            'ProviderFixture',
            provides: [[
                'id' => 'crm.fixture.alpha.v1',
                'interface' => 'Milpa\\Fixtures\\AlphaInterface',
                'contractVersion' => '1.0.0',
                'service' => 'Milpa\\Fixtures\\AlphaService',
            ]],
        );
        $this->writeCanonicalPlugin(
            'CanonicalOrphanFixture',
            requires: [[
                'id' => 'crm.fixture.ghost.v1',
                'interface' => 'Milpa\\Fixtures\\GhostInterface',
                'constraint' => '^1.0',
            ]],
        );
        $this->enable(['CanonicalOrphanFixture', 'ProviderFixture']);
        $this->plugins->addPluginPath($this->tmp . '/plugins');

        try {
            $this->plugins->loadPlugins();
            $this->fail('A rich hard requires no plugin provides must refuse to boot.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MILPA_CAPABILITY_MISSING', $e->getMessage());
            $this->assertStringContainsString('crm.fixture.ghost.v1', $e->getMessage());
        }
    }

    public function testTheCacheHitPathRevalidatesTheCanonicalGraphAndBoots(): void
    {
        $this->writeCanonicalFixture();
        $this->plugins->addPluginPath($this->tmp . '/plugins');
        $this->plugins->loadPlugins();

        $cacheFile = $this->tmp . '/storage/cache/plugins.php';
        $this->assertFileExists($cacheFile);

        // Fresh instance, warm cache: loadFromCache() must re-validate the record-shaped graph
        // (the parse seam again — a cached rich requires used to TypeError) against the tmp-root
        // profile (setUp wrote it, so the hostProfile === null short-circuit cannot apply) and
        // boot it. The profile's own `crm.fixture.alpha.v1@^1.0` demand closes through the cached
        // ProviderFixture record — cached from the ATTRIBUTE, not from a re-read manifest.
        $rerun = $this->newManager();

        // The setUp fixture is itself pinned (F1, T4 review): the re-validation the assertions
        // below rely on only engages when the tmp-root milpa.json actually loads as a real host
        // profile — drop setUp's write and this fails HERE, instead of passing silently through
        // the `hostProfile === null` short-circuit (which boots the cache WITHOUT re-validating,
        // the exact blind spot this suite exists to keep closed). loadHostProfile() is the same
        // private seam cachedGraphIsBootable() consults on the rerun instance.
        $this->assertFileExists($this->tmp . '/milpa.json', 'setUp must write the tmp-root host profile');
        $profile = (new \ReflectionMethod($rerun, 'loadHostProfile'))->invoke($rerun);
        $this->assertInstanceOf(HostProfile::class, $profile, 'the tmp-root milpa.json must load as a REAL profile — null would short-circuit the cache re-validation');
        $this->assertSame(['crm.fixture.alpha.v1@^1.0'], $profile->requiredCapabilities, 'the profile must carry the demand that forces the re-validation to resolve the cached graph');
        $rerun->addPluginPath($this->tmp . '/plugins');
        $rerun->loadPlugins();

        $this->assertSame(
            ['ProviderFixture', 'ConsumerFixture'],
            array_column($rerun->getPluginsMetadata(), 'name'),
            'the cache-hit path must re-validate and boot the same canonical graph'
        );
        $this->assertSame(
            [],
            $this->recordsContaining('warning', 'Falling back to a full plugin scan'),
            'a cached graph that satisfies the profile must boot from the cache, not fall back'
        );
    }

    /**
     * D5, negatively: a plugin's own `milpa.json` that is not even syntactically valid JSON must
     * not affect the boot at all — the manager never reads a plugin's manifest for metadata, so
     * there is nothing for a corrupt one to break. This replaces the host suite's illegible-
     * manifest-fallback test, whose premise (the manager falls back to the attribute when the
     * manifest cannot be read) no longer applies: there is no manifest read to fall back FROM.
     */
    public function testACorruptPluginManifestDoesNotAffectTheBoot(): void
    {
        $this->writeCanonicalPlugin(
            'ProviderFixture',
            provides: [[
                'id' => 'crm.fixture.alpha.v1',
                'interface' => 'Milpa\\Fixtures\\AlphaInterface',
                'contractVersion' => '1.0.0',
                'service' => 'Milpa\\Fixtures\\AlphaService',
            ]],
        );
        // Corrupt the plugin's OWN milpa.json after writeCanonicalPlugin() wrote a valid one —
        // the manager's scan path only reflects the #[PluginMetadata] attribute, so this file is
        // never opened during loadPlugins().
        file_put_contents($this->tmp . '/plugins/ProviderFixturePlugin/milpa.json', '{not json');
        $this->enable(['ProviderFixture']);
        $this->plugins->addPluginPath($this->tmp . '/plugins');

        $this->plugins->loadPlugins();

        $metadata = $this->plugins->getPluginsMetadata();
        $this->assertCount(1, $metadata);
        $this->assertSame('ProviderFixture', $metadata[0]['name']);
        $this->assertSame('crm.fixture.alpha.v1', $metadata[0]['provides'][0]['id']);
    }

    /**
     * Two canonical plugins: Consumer requires (by capability id, `^1.0`) what Provider provides —
     * scan order (alphabetical: Consumer first) INVERTS the boot order the resolver must produce.
     */
    private function writeCanonicalFixture(): void
    {
        $this->writeCanonicalPlugin(
            'ProviderFixture',
            provides: [[
                'id' => 'crm.fixture.alpha.v1',
                'interface' => 'Milpa\\Fixtures\\AlphaInterface',
                'contractVersion' => '1.0.0',
                'service' => 'Milpa\\Fixtures\\AlphaService',
            ]],
        );
        $this->writeCanonicalPlugin(
            'ConsumerFixture',
            requires: [[
                'id' => 'crm.fixture.alpha.v1',
                'interface' => 'Milpa\\Fixtures\\AlphaInterface',
                'constraint' => '^1.0',
            ]],
        );
        $this->enable(['ConsumerFixture', 'ProviderFixture']);
    }

    /**
     * A plugin class (attribute mirrors the manifest — no drift, unless the caller deliberately
     * diverges $attributeVersion/$manifestVersion) plus its CANONICAL milpa.json. Since D5, the
     * boot path (`PluginsManager::getMetadata()`) reads ONLY the attribute; the manifest here
     * exists purely as the on-disk distribution artifact these tests prove is inert to the boot.
     *
     * @param list<array<string, mixed>> $provides
     * @param list<array<string, mixed>> $requires
     */
    private function writeCanonicalPlugin(
        string $name,
        array $provides = [],
        array $requires = [],
        string $attributeVersion = '1.0.0',
        string $manifestVersion = '1.0.0',
    ): void {
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
                version: '{$attributeVersion}',
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

        // Every call site for a given $name in this file writes byte-identical PHP (the
        // attribute's version stays at the '1.0.0' default everywhere — even in the divergence
        // test, only $manifestVersion is overridden there) — guard the require against a fatal
        // redeclaration across this file's several tests, each writing to a fresh $this->tmp.
        $fqcn = "Milpa\\Plugins\\{$name}Plugin\\{$name}Plugin";
        if (!class_exists($fqcn, false)) {
            require_once $file;
        }

        file_put_contents($dir . '/milpa.json', (string) json_encode([
            'name' => 'milpa/' . strtolower($name . 'Plugin'),
            'displayName' => $name,
            'version' => $manifestVersion,
            'type' => 'Service',
            'capabilities' => [
                'provides' => $provides,
                'requires' => $requires,
                'suggests' => [],
            ],
            'entrypoint' => $name . 'Plugin.php',
            'namespace' => 'Milpa\\Plugins\\' . $name . 'Plugin',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * The tmp-root `milpa.json` host profile — the same shape as the real root, with the tightened
     * `allowedLegacyContracts: []` posture. Writing it (in setUp, and again mid-test to move the
     * profile under a warm cache) is what makes `loadHostProfile()` non-null, so BOTH gates apply.
     *
     * @param list<string> $requiredCapabilities
     */
    private function writeHostProfile(array $requiredCapabilities): void
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

    /**
     * The compound canonical pin (6b deferred): a warm, valid cache whose
     * HOST PROFILE then tightens must be rejected on the cache-hit
     * re-validation (learnable warning + fallback) AND refused again by the
     * fresh gate — unlike the poisoned-cache case, disk truth cannot heal a
     * profile the plugins genuinely do not satisfy.
     */
    public function testAProfileChangeBlocksBothTheWarmCacheAndTheFreshGate(): void
    {
        $this->writeCanonicalPlugin('Compound', provides: [[
            'id' => 'crm.fixture.alpha.v1',
            'interface' => 'Milpa\\Fixtures\\AlphaInterface',
            'contractVersion' => '1.0.0',
            'service' => 'Milpa\\Fixtures\\AlphaService',
        ]]);
        $this->enable(['Compound']);
        $this->plugins->addPluginPath($this->tmp . '/plugins');

        // First boot: warm the plugins cache under the satisfiable profile (setUp's demand).
        $this->plugins->loadPlugins();
        $this->assertFileExists($this->tmp . '/storage/cache/plugins.php');

        // The profile tightens under the warm cache: demand nobody provides.
        $this->writeHostProfile(requiredCapabilities: ['crm.ghost.capability.v1@^1.0']);

        $rerun = $this->newManager();
        $rerun->addPluginPath($this->tmp . '/plugins');

        try {
            $rerun->loadPlugins();
            $this->fail('The fresh gate must refuse a graph that cannot satisfy the tightened profile.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MILPA_CAPABILITY_MISSING', $e->getMessage());
        }

        // The cache-hit re-validation spoke first: blocked + learnable + fallback.
        $warnings = $this->recordsContaining('warning', 'Cached plugin graph is blocked');
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('Falling back to a full plugin scan', $warnings[0]);
    }
}
