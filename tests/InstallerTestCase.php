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

use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Plugin\Contracts\ComposerResult;
use Milpa\Plugin\Contracts\ComposerRunnerInterface;
use Milpa\Plugin\Contracts\ExecutedMigration;
use Milpa\Plugin\Contracts\MigrationContext;
use Milpa\Plugin\Contracts\MigrationLedgerInterface;
use Milpa\Plugin\Contracts\PluginRecord;
use Milpa\Plugin\Contracts\PluginDownloaderInterface;
use Milpa\Plugin\DependencyResolver;
use Milpa\Plugin\LockFileManager;
use Milpa\Plugin\PluginInstaller;
use Milpa\Plugin\PluginMigrationRunner;
use Milpa\Plugin\Registry\InMemoryPluginRegistry;
use Milpa\ValueObjects\SemanticVersion;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * Shared scaffolding for the installer suites: a tmp root with a plugins/
 * dir, an in-memory activation store, an in-memory migration ledger, an inert
 * DI container, and the doubles for the two ports that would otherwise reach
 * the network and the shell.
 *
 * It lives apart from any one suite because install and update are two long
 * pipelines over the same collaborators — duplicating this setup in each is
 * how the two drift until they no longer test the same installer.
 */
abstract class InstallerTestCase extends TestCase
{
    protected string $tmpRoot;
    protected InMemoryPluginRegistry $registry;
    protected PluginMigrationRunner $migrationRunner;
    protected DependencyResolver $resolver;
    protected LockFileManager $lockManager;
    protected DIContainerInterface $container;

    /** @var list<string> */
    protected array $extractDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/milpa_installer_' . uniqid();
        mkdir($this->tmpRoot . '/plugins', 0755, true);

        $this->registry = new InMemoryPluginRegistry();

        // In-memory ledger over an array (T1 pattern): implements the full
        // port. None of these tests' fixtures ship a Migrations/ directory,
        // so the ledger is never actually touched — it only needs to exist
        // to satisfy the v2 runner's constructor.
        $ledger = new class () implements MigrationLedgerInterface {
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
        $this->migrationRunner = new PluginMigrationRunner($ledger, new MigrationContext(new \stdClass(), new NullLogger()));

        $this->resolver = new DependencyResolver($this->tmpRoot);
        $this->lockManager = new LockFileManager($this->tmpRoot);

        // Inert DI container double: none of these fixtures declare a class
        // under Milpa\Plugins\{Name}\{Name}, so the installer's install()/
        // uninstall() hook is never reached and this container never serves
        // anything — it only satisfies the constructor's type.
        $this->container = new class () implements DIContainerInterface {
            public function registerService(string $id, string|object $classOrInstance): void
            {
            }

            public function get(string $id): mixed
            {
                throw new \RuntimeException("not registered: {$id}");
            }

            public function has(string $id): bool
            {
                return false;
            }

            public function tryGet(string $id): mixed
            {
                return null;
            }

            public function resolve(string $className, bool $singleton = true): mixed
            {
                throw new \RuntimeException('no autowiring');
            }

            public function compileContainer(): void
            {
            }

            public function getContainer(): ContainerInterface
            {
                return $this;
            }
        };
    }

    protected function tearDown(): void
    {
        foreach ($this->extractDirs as $dir) {
            $this->rrmdir($dir);
        }
        $this->rrmdir($this->tmpRoot);
        parent::tearDown();
    }

    protected function makeInstaller(PluginDownloaderInterface $downloader, ComposerRunnerInterface $composerRunner): PluginInstaller
    {
        return new PluginInstaller(
            $this->container,
            $this->registry,
            $this->migrationRunner,
            $composerRunner,
            $downloader,
            $this->resolver,
            $this->lockManager,
            $this->tmpRoot,
        );
    }

    /**
     * A source of plugins that must never be reached: every method on the port
     * throws. Weaker doubles only prove the methods a test happens to stub were
     * not called; this one proves the installer never touched the source at all.
     */
    protected function forbiddenDownloader(string $why): PluginDownloaderInterface
    {
        return new class ($why) implements PluginDownloaderInterface {
            public function __construct(private readonly string $why)
            {
            }

            /**
             * @return array{owner: string, repo: string, constraint: ?string}
             */
            public function parseSource(string $source): array
            {
                throw new \LogicException($this->why);
            }

            public function resolveVersion(string $owner, string $repo, ?string $constraint = null): SemanticVersion
            {
                throw new \LogicException($this->why);
            }

            public function download(string $owner, string $repo, SemanticVersion $version): string
            {
                throw new \LogicException($this->why);
            }

            public function cleanup(string $path): void
            {
                throw new \LogicException($this->why);
            }
        };
    }

    /**
     * A composer runner double that records every call. With no result
     * programmed, an unexpected call throws — a strong "must not be called"
     * assertion for the abort-before-composer tests.
     */
    protected function composerSpy(?ComposerResult $result = null): ComposerRunnerInterface
    {
        return new class ($result) implements ComposerRunnerInterface {
            /** @var list<array{workingDir: string, packages: array<string, string>}> */
            public array $calls = [];

            public function __construct(private readonly ?ComposerResult $result)
            {
            }

            public function requirePackages(string $workingDir, array $packages): ComposerResult
            {
                $this->calls[] = ['workingDir' => $workingDir, 'packages' => $packages];

                return $this->result ?? throw new \LogicException('composer runner must not be called in this test');
            }
        };
    }

    protected function makeRecord(string $name, ?string $source = 'local', bool $installed = true, bool $enabled = true): PluginRecord
    {
        return new PluginRecord(
            name: $name,
            version: '1.0.0',
            author: 'Acme',
            site: 'https://example.com',
            type: 'Service',
            installed: $installed,
            enabled: $enabled,
            source: $source,
            installedVersion: $source !== null && $source !== 'local' ? '1.0.0' : null,
        );
    }

    /**
     * Builds a network-free extract dir with a valid milpa.json + entrypoint.
     * The entrypoint deliberately declares no class — install()/uninstall()
     * hooks are out of scope for this suite and stay unreachable.
     *
     * @param array<string, string> $composerDeps
     * @param array<string, string> $pluginDeps
     */
    protected function buildExtractDir(string $pluginName, string $version = '1.0.0', array $composerDeps = [], array $pluginDeps = [], ?string $entrypointBody = null): string
    {
        // Anidado dentro de un padre propio, como exige
        // PluginDownloaderInterface::download(): el instalador limpia el PADRE
        // del path devuelto, así que un extract dir de primer nivel le
        // entregaría /tmp a un borrado recursivo.
        $parent = sys_get_temp_dir() . '/milpa_installer_extract_' . uniqid();
        $dir = $parent . '/package';
        mkdir($dir, 0755, true);
        $this->extractDirs[] = $parent;

        $manifest = [
            'name' => 'acme/' . strtolower($pluginName),
            'version' => $version,
            'entrypoint' => $pluginName . '.php',
            'namespace' => 'Milpa\\Plugins\\' . $pluginName,
            'type' => 'Service',
            'dependencies' => [
                'composer' => $composerDeps,
                'plugins' => $pluginDeps === [] ? (object) [] : $pluginDeps,
            ],
        ];
        file_put_contents($dir . '/milpa.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        file_put_contents(
            $dir . '/' . $pluginName . '.php',
            $entrypointBody ?? "<?php\n// fixture entrypoint — deliberately does not declare the plugin class\n",
        );

        return $dir;
    }

    protected function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
