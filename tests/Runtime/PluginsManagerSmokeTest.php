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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Construction smoke of the ported manager: injected config, no globals, no
 * static state — two instances in one process do not collide.
 */
final class PluginsManagerSmokeTest extends TestCase
{
    private function manager(string $tmp): PluginsManager
    {
        $container = $this->createMock(DIContainerInterface::class);
        $container->method('get')->willReturnCallback(
            static fn (string $id): mixed => $id === LoggerInterface::class ? new NullLogger() : null
        );
        $container->method('has')->willReturn(false);

        return new PluginsManager(
            $container,
            new InMemoryPluginRegistry(),
            new ManagerConfig(
                cacheDir: $tmp . '/storage/cache',
                hostManifestPath: null,
                devMode: true,
                environment: 'CLI',
            ),
        );
    }

    /** Two managers in ONE process scan independently — the static is gone. */
    public function testTwoInstancesInOneProcessDoNotCollide(): void
    {
        $tmp = sys_get_temp_dir() . '/milpa-mgr-smoke-' . uniqid();
        mkdir($tmp . '/storage/cache', 0777, true);
        mkdir($tmp . '/plugins', 0777, true);

        $first = $this->manager($tmp);
        $first->addPluginPath($tmp . '/plugins');
        $first->loadPlugins();

        $second = $this->manager($tmp);
        $second->addPluginPath($tmp . '/plugins');
        $second->loadPlugins(); // legacy static would throw "Duplicate plugin name" with real plugins

        $this->assertSame([], $first->getPluginsMetadata());
        $this->assertSame([], $second->getPluginsMetadata());
        $this->assertSame([], $second->getPlugins());
    }

    /** An empty registry read (healthy-zero OR degraded) is never persisted — the cache cannot be poisoned. */
    public function testAnEmptyEnabledReadIsNotPersistedToTheCacheFile(): void
    {
        $tmp = sys_get_temp_dir() . '/milpa-mgr-nopersist-' . uniqid();
        mkdir($tmp . '/storage/cache', 0777, true);
        mkdir($tmp . '/plugins', 0777, true);

        $manager = $this->manager($tmp);
        $manager->addPluginPath($tmp . '/plugins');
        $manager->loadPlugins();

        $this->assertFileDoesNotExist($tmp . '/storage/cache/enabled_plugins.php');
    }
}
