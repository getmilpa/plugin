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

use Milpa\Eventing\EventDispatcher;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Event\EventSubscriberInterface;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Interfaces\Tooling\ToolProviderInterface;
use Milpa\Plugin\Registry\InMemoryPluginRegistry;
use Milpa\Plugin\Runtime\ManagerConfig;
use Milpa\Plugin\Runtime\PluginsManager;
use Milpa\Plugin\Tests\Fixtures\ApiFixturePlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Ported (Ola 6b T6) from the host's `PluginsTest` — the public/private-by-reflection API
 * surface of the manager (paths, metadata reads, tool-provider prompt sections, the private
 * scan/boot/register helpers), as opposed to the boot-orchestration suites T5 already ported
 * ({@see PluginsManagerFreshPathTest}, {@see PluginsManagerCacheRevalidationTest},
 * {@see PluginsManagerAttributeAuthorityTest}). The per-process `Plugins::$plugins` static and
 * its `setUp()` reset are gone: {@see PluginsManager} carries `$plugins` as instance state, so
 * every test in this file runs in ONE shared process with nothing to reset between them.
 *
 * Deviations from a literal line-for-line port:
 *
 * - The 6 host tests that exercised `\Milpa\Plugins\AntiScannerPlugin\AntiScannerPlugin::class`
 *   as "a real class with real metadata" now use {@see ApiFixturePlugin} instead (this
 *   package's own fixture — the host plugin does not exist here); the matching
 *   `enabledPlugins` reflection value is `'ApiFixture'` (the fixture's declared name).
 *   `testIsEnabledReflectsEnabledPluginsList` keeps the host's bare `'AntiScannerPlugin'`
 *   STRING unchanged — it is an arbitrary label `isEnabled()` compares against, never a class
 *   reference, so there is nothing to substitute.
 * - `testGetPluginClassNameFromFile` drops its `markTestSkipped` guard on a `DS` constant the
 *   port never needs (it takes `DIRECTORY_SEPARATOR` directly) — the test now always runs.
 * - The host's `loadPluginsPath()`/`loadPlugin()` — a second, unused scan/load pair that lived
 *   alongside `scanPluginsPath()`/`scanPlugin()` — were never ported to {@see PluginsManager}
 *   (`getPluginClassNameFromFile` is even shared between the two legacy pairs, hence its test
 *   above ports untouched). ALL THREE tests that reflected on the dead pair are dropped, not
 *   just the two the brief enumerates by name
 *   (`testLoadPluginsPathLogsWarningForNonExistentDirectory`,
 *   `testLoadPluginReturnsFalseForMissingMetadata`): `testLoadPluginReturnsFalseForDisabledPlugin`
 *   reflects on `loadPlugin` too (a method that does not exist on this class) and is structurally
 *   IDENTICAL to `testScanPluginReturnsFalseForDisabledPlugin` below but for that one reflected
 *   method name — retargeting it at `scanPlugin` would just duplicate that test verbatim. See the
 *   task report for the full accounting.
 * - One test is NEW: `testLoadPluginsWarnsWhenNoPathsRegistered`, pinning the
 *   `loadPlugins()`-with-zero-paths warning the host's OTHER `tests/Unit/PluginsTest.php` covered
 *   but this suite's source file did not. It needs the dispatched log MESSAGE, not just a
 *   PHPUnit `->expects()` call count, so `$logger` (still the `createMock(LoggerInterface::class)`
 *   every other test in this file drives via `->expects()`/`->method()`) additionally routes its
 *   `warning()` calls into `$logRecords` — the T5 suites' record-capturing pattern, layered on
 *   top of the mock instead of replacing it, so none of the 30-odd ported `->expects()`
 *   assertions change shape.
 */
final class PluginsManagerApiTest extends TestCase
{
    private string $tmp;

    private DIContainerInterface $container;

    private LoggerInterface $logger;

    private PluginsManager $plugins;

    /** @var list<array{level: string, message: string}> */
    private array $logRecords = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'milpa-api-suite-' . uniqid();

        $this->logger = $this->createMock(LoggerInterface::class);
        // Layered on top of every test's own ->expects()/->method() configuration below — only
        // testLoadPluginsWarnsWhenNoPathsRegistered reads $logRecords; every other test in this
        // file never triggers warning(), so this callback is inert for them.
        $this->logger->method('warning')->willReturnCallback(
            function (string|\Stringable $message, array $context = []): void {
                $this->logRecords[] = ['level' => 'warning', 'message' => (string) $message];
            }
        );

        $this->container = $this->createMock(DIContainerInterface::class);
        $this->container->method('get')->willReturnCallback(function ($id) {
            if ($id === LoggerInterface::class) {
                return $this->logger;
            }
            return null;
        });
        $this->container->method('has')->willReturn(false);

        $this->plugins = $this->newManager($this->container);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmp)) {
            $this->removeDir($this->tmp);
        }
        parent::tearDown();
    }

    private function newManager(DIContainerInterface $container): PluginsManager
    {
        return new PluginsManager(
            $container,
            new InMemoryPluginRegistry(),
            new ManagerConfig(
                cacheDir: $this->tmp,
                hostManifestPath: null,
                devMode: true,
                environment: 'CLI',
            ),
        );
    }

    /**
     * @param list<array<string, mixed>> $metadata
     */
    private function setScannedPlugins(array $metadata): void
    {
        $reflection = new \ReflectionClass($this->plugins);
        $prop = $reflection->getProperty('plugins');
        $prop->setAccessible(true);
        $prop->setValue($this->plugins, $metadata);
    }

    public function testAddPluginPath(): void
    {
        $this->plugins->addPluginPath('/path/to/plugins');

        $paths = $this->plugins->getPluginsPaths();

        $this->assertCount(1, $paths);
        $this->assertContains('/path/to/plugins', $paths);
    }

    public function testAddPluginPathNoDuplicates(): void
    {
        $this->plugins->addPluginPath('/path/to/plugins');
        $this->plugins->addPluginPath('/path/to/plugins');

        $paths = $this->plugins->getPluginsPaths();

        $this->assertCount(1, $paths);
    }

    public function testAddMultiplePluginPaths(): void
    {
        $this->plugins->addPluginPath('/path/one');
        $this->plugins->addPluginPath('/path/two');
        $this->plugins->addPluginPath('/path/three');

        $paths = $this->plugins->getPluginsPaths();

        $this->assertCount(3, $paths);
    }

    public function testRemovePluginPath(): void
    {
        $this->plugins->addPluginPath('/path/to/plugins');
        $this->plugins->addPluginPath('/path/to/other');

        $this->plugins->removePluginPath('/path/to/plugins');

        $paths = $this->plugins->getPluginsPaths();
        $this->assertCount(1, $paths);
        $this->assertNotContains('/path/to/plugins', $paths);
    }

    public function testRemoveNonExistentPath(): void
    {
        $this->plugins->addPluginPath('/path/one');

        $this->plugins->removePluginPath('/nonexistent');

        $paths = $this->plugins->getPluginsPaths();
        $this->assertCount(1, $paths);
    }

    public function testGetPluginsPaths(): void
    {
        $this->assertEquals([], $this->plugins->getPluginsPaths());

        $this->plugins->addPluginPath('/test/path');

        $this->assertIsArray($this->plugins->getPluginsPaths());
    }

    public function testGetPluginsMetadata(): void
    {
        $this->setScannedPlugins([
            ['name' => 'TestPlugin', 'version' => '1.0.0'],
            ['name' => 'AnotherPlugin', 'version' => '2.0.0'],
        ]);

        $metadata = $this->plugins->getPluginsMetadata();

        $this->assertCount(2, $metadata);
        $this->assertEquals('TestPlugin', $metadata[0]['name']);
    }

    public function testGetToolProviderPromptSectionsWithNoPlugins(): void
    {
        $sections = $this->plugins->getToolProviderPromptSections();

        $this->assertEquals([], $sections);
    }

    public function testGetToolProviderPromptSectionsSkipsNonProviders(): void
    {
        $this->setScannedPlugins([
            ['name' => 'TestPlugin', 'class' => 'NonExistentClass'],
        ]);

        $this->container->method('has')->willReturn(false);

        $sections = $this->plugins->getToolProviderPromptSections();

        $this->assertEquals([], $sections);
    }

    // ========== Tests using reflection for private methods ==========

    public function testDirectoryExistsReturnsTrueForExistingDirectory(): void
    {
        $reflection = new \ReflectionClass($this->plugins);
        $method = $reflection->getMethod('directoryExists');
        $method->setAccessible(true);

        $result = $method->invoke($this->plugins, __DIR__);

        $this->assertTrue($result);
    }

    public function testDirectoryExistsReturnsFalseForNonExistingDirectory(): void
    {
        $this->logger->expects($this->once())->method('error');

        $reflection = new \ReflectionClass($this->plugins);
        $method = $reflection->getMethod('directoryExists');
        $method->setAccessible(true);

        $result = $method->invoke($this->plugins, '/nonexistent/path/that/does/not/exist');

        $this->assertFalse($result);
    }

    public function testGetMetadataThrowsForNonExistentClass(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('PluginSystem class not found');

        $this->plugins->getMetadata('NonExistentClass');
    }

    public function testGetMetadataThrowsForClassWithoutMetadata(): void
    {
        // stdClass exists but has no PluginMetadata attribute
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('has no metadata defined');

        $this->plugins->getMetadata(\stdClass::class);
    }

    public function testGetMetadataReturnsValidMetadata(): void
    {
        // Real plugin class from this package's fixtures, replacing the host's AntiScannerPlugin.
        $metadata = $this->plugins->getMetadata(ApiFixturePlugin::class);

        $this->assertIsArray($metadata);
        $this->assertArrayHasKey('name', $metadata);
        $this->assertArrayHasKey('version', $metadata);
        $this->assertArrayHasKey('type', $metadata);
    }

    public function testScanPluginsPathLogsForNonExistentDirectory(): void
    {
        $this->logger->expects($this->once())->method('error');

        $this->plugins->scanPluginsPath('/nonexistent/scan/path');
    }

    public function testGetToolProviderPromptSectionsWithMissingClass(): void
    {
        $this->setScannedPlugins([
            ['name' => 'TestPlugin'], // No 'class' key
        ]);

        $sections = $this->plugins->getToolProviderPromptSections();

        $this->assertEquals([], $sections);
    }

    // ========== Additional Tests for Coverage ==========

    public function testGetToolProviderPromptSectionsWithToolProvider(): void
    {
        // Create a mock plugin that implements ToolProviderInterface
        $mockPlugin = $this->createMock(ToolProviderInterface::class);
        $mockPlugin->method('getPromptSections')->willReturn([
            'Section 1',
            'Section 2',
        ]);

        $pluginClass = get_class($mockPlugin);

        $this->container = $this->createMock(DIContainerInterface::class);
        $this->container->method('get')->willReturnCallback(function ($id) use ($mockPlugin) {
            if ($id === LoggerInterface::class) {
                return $this->logger;
            }
            return $mockPlugin;
        });
        $this->container->method('has')->willReturn(true);

        $this->plugins = $this->newManager($this->container);
        $this->setScannedPlugins([
            ['name' => 'MockPlugin', 'class' => $pluginClass],
        ]);

        $sections = $this->plugins->getToolProviderPromptSections();

        $this->assertCount(3, $sections); // 2 sections + 1 blank line
        $this->assertContains('Section 1', $sections);
        $this->assertContains('Section 2', $sections);
    }

    public function testGetPluginClassNameFromFile(): void
    {
        $reflection = new \ReflectionClass($this->plugins);
        $method = $reflection->getMethod('getPluginClassNameFromFile');
        $method->setAccessible(true);

        $result = $method->invoke(
            $this->plugins,
            '/plugins' . DIRECTORY_SEPARATOR . 'MyPlugin' . DIRECTORY_SEPARATOR . 'MyPlugin.php',
            '/plugins',
            'Milpa\\Plugins'
        );

        $this->assertEquals('Milpa\\Plugins\\MyPlugin\\MyPlugin', $result);
    }

    public function testBootPluginWithBootMethod(): void
    {
        $mockPlugin = $this->createMock(PluginInterface::class);
        $mockPlugin->expects($this->once())->method('boot');

        $reflection = new \ReflectionClass($this->plugins);
        $method = $reflection->getMethod('bootPlugin');
        $method->setAccessible(true);

        $method->invoke($this->plugins, $mockPlugin);
    }

    public function testPluginBootingVetoSkipsBootAndBootedEventButOtherPluginsStillBoot(): void
    {
        // Real dispatcher (not a mock) so the InterceptionSlot contract — stop() during
        // plugin.booting actually halting the corresponding boot() call — is exercised
        // end-to-end, not just asserted via mock expectations.
        $dispatcher = new EventDispatcher($this->logger);

        $bootedNames = [];
        $dispatcher->subscribe('plugin.booted', function (string $event, array $payload) use (&$bootedNames) {
            $bootedNames[] = $payload['event']->pluginName;
        });

        // Feature-flag-style listener: veto PluginX specifically, let everything else through.
        $dispatcher->subscribe('plugin.booting', function (string $event, array $payload) {
            if ($payload['event']->pluginName === 'PluginX') {
                $payload['slot']->stop();
            }
        });

        // Two distinct anonymous classes (not two createMock(PluginInterface::class) calls,
        // which PHPUnit is free to satisfy with the SAME generated mock class — that would
        // collapse the two container keys below into one and defeat this test's premise).
        $vetoedPlugin = new class () implements PluginInterface {
            public int $bootCalls = 0;

            public function __construct(?DIContainerInterface $container = null)
            {
            }

            public function boot(): void
            {
                $this->bootCalls++;
            }

            public function install(): void
            {
            }

            public function uninstall(): void
            {
            }

            public function enable(): void
            {
            }

            public function disable(): void
            {
            }
        };

        $allowedPlugin = new class () implements PluginInterface {
            public int $bootCalls = 0;

            public function __construct(?DIContainerInterface $container = null)
            {
            }

            public function boot(): void
            {
                $this->bootCalls++;
            }

            public function install(): void
            {
            }

            public function uninstall(): void
            {
            }

            public function enable(): void
            {
            }

            public function disable(): void
            {
            }
        };

        $vetoedClass = get_class($vetoedPlugin);
        $allowedClass = get_class($allowedPlugin);
        $this->assertNotSame($vetoedClass, $allowedClass, 'Test fixture sanity check: the two plugins must be distinct classes');

        $container = $this->createMock(DIContainerInterface::class);
        $container->method('has')->willReturnCallback(
            fn ($id) => in_array($id, [MilpaEventDispatcherInterface::class, $vetoedClass, $allowedClass], true)
        );
        $container->method('get')->willReturnCallback(
            function ($id) use ($dispatcher, $vetoedPlugin, $allowedPlugin, $vetoedClass, $allowedClass) {
                return match ($id) {
                    LoggerInterface::class => $this->logger,
                    MilpaEventDispatcherInterface::class => $dispatcher,
                    $vetoedClass => $vetoedPlugin,
                    $allowedClass => $allowedPlugin,
                    default => null,
                };
            }
        );

        $plugins = $this->newManager($container);

        $reflection = new \ReflectionClass($plugins);
        $method = $reflection->getMethod('registerAndBoot');
        $method->setAccessible(true);

        // Simulates the plugin boot loop: PluginX is vetoed, PluginY boots right after it.
        $method->invoke($plugins, $vetoedClass, 'PluginX', ['name' => 'PluginX']);
        $method->invoke($plugins, $allowedClass, 'PluginY', ['name' => 'PluginY']);

        $this->assertSame(0, $vetoedPlugin->bootCalls, 'Vetoed plugin boot() must never be called');
        $this->assertSame(1, $allowedPlugin->bootCalls, 'Non-vetoed plugin boot() must still be called once');
        $this->assertSame(['PluginY'], $bootedNames, 'plugin.booted must fire only for the non-vetoed plugin');
        $this->assertNull($plugins->getPlugin('PluginX'), 'Vetoed plugin must not be tracked as a booted instance');
        $this->assertSame(
            $allowedPlugin,
            $plugins->getPlugin('PluginY'),
            'Non-vetoed plugin must still boot and be tracked — the veto must not abort the boot loop'
        );
    }

    public function testRegisterPluginToolsSkipsNonToolProvider(): void
    {
        // Plugin without ToolProviderInterface
        $mockPlugin = $this->createMock(PluginInterface::class);

        $reflection = new \ReflectionClass($this->plugins);
        $method = $reflection->getMethod('registerPluginTools');
        $method->setAccessible(true);

        // Should not throw, just skip silently
        $method->invoke($this->plugins, $mockPlugin, 'TestClass');
        $this->assertTrue(true);
    }

    public function testRegisterPluginEventSubscriptionsSkipsNonSubscriber(): void
    {
        // Plugin without EventSubscriberInterface
        $mockPlugin = $this->createMock(PluginInterface::class);

        $reflection = new \ReflectionClass($this->plugins);
        $method = $reflection->getMethod('registerPluginEventSubscriptions');
        $method->setAccessible(true);

        // Should not throw, just return
        $method->invoke($this->plugins, $mockPlugin, 'TestClass');

        $this->assertTrue(true); // Assert we got here without error
    }

    public function testRegisterPluginEventSubscriptionsWithNoDispatcher(): void
    {
        // Create anonymous class that implements both interfaces
        $mockPlugin = new class ($this->container) implements PluginInterface, EventSubscriberInterface {
            public function __construct(private $container)
            {
            }
            public function boot(): void
            {
            }
            public function install(): void
            {
            }
            public function uninstall(): void
            {
            }
            public function enable(): void
            {
            }
            public function disable(): void
            {
            }
            public static function getSubscribedEvents(): array
            {
                return ['test.event' => 'handleEvent'];
            }
            public function handleEvent(string $event, array $payload): void
            {
            }
        };

        $this->container->method('has')->with(MilpaEventDispatcherInterface::class)->willReturn(false);

        $reflection = new \ReflectionClass($this->plugins);
        $method = $reflection->getMethod('registerPluginEventSubscriptions');
        $method->setAccessible(true);

        // Should not throw, just skip when dispatcher not available
        $method->invoke($this->plugins, $mockPlugin, get_class($mockPlugin));

        $this->assertTrue(true);
    }

    public function testScanPluginReturnsFalseForDuplicateName(): void
    {
        // First, add ApiFixture as already loaded
        $this->setScannedPlugins([
            ['name' => 'ApiFixture', 'version' => '1.0.0'],
        ]);

        // Enable the plugin
        $reflection = new \ReflectionClass($this->plugins);
        $enabledProp = $reflection->getProperty('enabledPlugins');
        $enabledProp->setAccessible(true);
        $enabledProp->setValue($this->plugins, ['ApiFixture']);

        // Expect error for duplicate
        $this->logger->expects($this->atLeastOnce())->method('error');

        $method = $reflection->getMethod('scanPlugin');
        $method->setAccessible(true);

        // This should fail because ApiFixture is already in the list
        $result = $method->invoke($this->plugins, ApiFixturePlugin::class);

        $this->assertFalse($result);
    }

    public function testScanPluginReturnsFalseForDisabledPlugin(): void
    {
        // Set empty enabled plugins
        $reflection = new \ReflectionClass($this->plugins);
        $enabledProp = $reflection->getProperty('enabledPlugins');
        $enabledProp->setAccessible(true);
        $enabledProp->setValue($this->plugins, []); // No plugins enabled

        $method = $reflection->getMethod('scanPlugin');
        $method->setAccessible(true);

        // Plugin should be disabled
        $result = $method->invoke($this->plugins, ApiFixturePlugin::class);

        $this->assertFalse($result);
    }

    public function testScanPluginReturnsTrueForValidPlugin(): void
    {
        // Enable ApiFixture by name
        $reflection = new \ReflectionClass($this->plugins);
        $enabledProp = $reflection->getProperty('enabledPlugins');
        $enabledProp->setAccessible(true);
        $enabledProp->setValue($this->plugins, ['ApiFixture']);

        $method = $reflection->getMethod('scanPlugin');
        $method->setAccessible(true);

        $result = $method->invoke($this->plugins, ApiFixturePlugin::class);

        $this->assertTrue($result);
    }

    public function testScanPluginReturnsFalseForMissingMetadata(): void
    {
        // Test with stdClass which has no PluginMetadata attribute
        $reflection = new \ReflectionClass($this->plugins);
        $method = $reflection->getMethod('scanPlugin');
        $method->setAccessible(true);

        // Should log error and return false
        $this->logger->expects($this->atLeastOnce())->method('error');

        $result = $method->invoke($this->plugins, \stdClass::class);

        $this->assertFalse($result);
    }

    public function testWriteCache(): void
    {
        $tempDir = sys_get_temp_dir() . '/plugins_test_' . uniqid();
        $cacheFile = $tempDir . '/plugins.php';

        $this->setScannedPlugins([
            ['name' => 'Plugin1', 'version' => '1.0.0', 'class' => 'TestClass1'],
            ['name' => 'Plugin2', 'version' => '2.0.0', 'class' => 'TestClass2'],
        ]);

        $reflection = new \ReflectionClass($this->plugins);
        $method = $reflection->getMethod('writeCache');
        $method->setAccessible(true);

        $method->invoke($this->plugins, $cacheFile);

        $this->assertFileExists($cacheFile);

        $cached = require $cacheFile;
        $this->assertCount(2, $cached);
        $this->assertEquals('Plugin1', $cached[0]['name']);

        // Cleanup
        unlink($cacheFile);
        rmdir($tempDir);
    }

    public function testGetMetadataReturnsAllFields(): void
    {
        // Use ApiFixturePlugin which has all metadata fields
        $metadata = $this->plugins->getMetadata(ApiFixturePlugin::class);

        $this->assertArrayHasKey('version', $metadata);
        $this->assertArrayHasKey('author', $metadata);
        $this->assertArrayHasKey('site', $metadata);
        $this->assertArrayHasKey('name', $metadata);
        $this->assertArrayHasKey('type', $metadata);
        $this->assertArrayHasKey('provides', $metadata);
        $this->assertArrayHasKey('requires', $metadata);
        $this->assertArrayHasKey('suggests', $metadata);
    }

    // ========== Task 6 (host): plugin-set introspection (#8) ==========

    public function testGetPluginsReturnsEmptyArrayWhenNothingBooted(): void
    {
        $this->assertSame([], $this->plugins->getPlugins());
    }

    public function testGetPluginReturnsNullForUnknownName(): void
    {
        $this->assertNull($this->plugins->getPlugin('NotBooted'));
    }

    public function testGetPluginsAndGetPluginReflectBootedInstance(): void
    {
        $mockPlugin = $this->createMock(PluginInterface::class);
        $mockPlugin->expects($this->once())->method('boot');

        $container = $this->createMock(DIContainerInterface::class);
        $container->method('has')->willReturn(true);
        $container->method('get')->willReturnCallback(function ($id) use ($mockPlugin) {
            if ($id === LoggerInterface::class) {
                return $this->logger;
            }
            return $mockPlugin;
        });

        $plugins = $this->newManager($container);

        $reflection = new \ReflectionClass($plugins);
        $method = $reflection->getMethod('registerAndBoot');
        $method->setAccessible(true);
        $method->invoke($plugins, get_class($mockPlugin), 'MockPlugin');

        $all = $plugins->getPlugins();
        $this->assertArrayHasKey('MockPlugin', $all);
        $this->assertSame($mockPlugin, $all['MockPlugin']);
        $this->assertSame($mockPlugin, $plugins->getPlugin('MockPlugin'));
        $this->assertNull($plugins->getPlugin('SomeOtherPlugin'));
    }

    public function testIsEnabledReflectsEnabledPluginsList(): void
    {
        $reflection = new \ReflectionClass($this->plugins);
        $enabledProp = $reflection->getProperty('enabledPlugins');
        $enabledProp->setAccessible(true);
        $enabledProp->setValue($this->plugins, ['AntiScannerPlugin']);

        $this->assertTrue($this->plugins->isEnabled('AntiScannerPlugin'));
        $this->assertFalse($this->plugins->isEnabled('NotEnabledPlugin'));
    }

    /** loadPlugins() with zero registered paths logs the legacy warning and boots nothing. */
    public function testLoadPluginsWarnsWhenNoPathsRegistered(): void
    {
        $this->plugins->loadPlugins();

        $this->assertNotEmpty(array_filter(
            $this->logRecords,
            static fn (array $r): bool => str_contains($r['message'], 'No plugins paths found'),
        ));
        $this->assertSame([], $this->plugins->getPlugins());
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
