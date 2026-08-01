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

namespace Milpa\Plugin\Tests\Operations;

use Milpa\Attributes\PluginMetadata;
use Milpa\Command\CommandProvider;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Plugin\Contracts\PluginRegistryInterface;
use Milpa\Plugin\Contracts\StateBaselineInterface;
use Milpa\Plugin\Operations\PluginManagementPlugin;
use Milpa\Plugin\Registry\InMemoryPluginRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The one plugin a host adds by hand.
 *
 * Its whole job is to be discoverable: the kernel checks every booted plugin
 * for {@see CommandProvider} and merges what it returns into the command
 * table, from which each surface builds its own shape. So what is worth
 * pinning is that it IS discoverable, that it reads its collaborators from the
 * container, and that a missing registry is a loud failure rather than an
 * empty menu.
 */
final class PluginManagementPluginTest extends TestCase
{
    /**
     * @param array<string, object> $services
     */
    private function container(array $services): DIContainerInterface
    {
        return new class ($services) implements DIContainerInterface {
            /** @param array<string, object> $services */
            public function __construct(private readonly array $services)
            {
            }

            public function registerService(string $id, string|object $classOrInstance): void
            {
            }

            public function get(string $id): mixed
            {
                return $this->services[$id] ?? null;
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }

            public function tryGet(string $id): mixed
            {
                return $this->services[$id] ?? null;
            }

            public function resolve(string $className, bool $singleton = true): mixed
            {
                throw new \RuntimeException('no autowiring');
            }

            public function compileContainer(): void
            {
            }

            public function getContainer(): \Psr\Container\ContainerInterface
            {
                return $this;
            }
        };
    }

    public function testTheKernelCanFindItsOperationsThroughTheDiscoverySeam(): void
    {
        // If this stops being a CommandProvider the operations simply stop
        // existing, everywhere at once, with nothing failing loudly.
        $plugin = new PluginManagementPlugin($this->container([
            PluginRegistryInterface::class => new InMemoryPluginRegistry(),
        ]));

        self::assertInstanceOf(CommandProvider::class, $plugin);
        self::assertInstanceOf(PluginInterface::class, $plugin);
    }

    public function testItCarriesTheMetadataThePluginsManagerNeedsToBootIt(): void
    {
        $metadata = (new \ReflectionClass(PluginManagementPlugin::class))
            ->getAttributes(PluginMetadata::class);

        self::assertCount(1, $metadata, 'Without metadata the manager cannot scan it.');
        self::assertSame('PluginManagement', $metadata[0]->newInstance()->name);
    }

    public function testWithOnlyARegistryItOffersTheReadAndToggleOperations(): void
    {
        $plugin = new PluginManagementPlugin($this->container([
            PluginRegistryInterface::class => new InMemoryPluginRegistry(),
        ]));

        self::assertSame(
            ['plugins.list', 'plugins.show', 'plugins.enable', 'plugins.disable', 'plugins.disable-unsafe', 'plugins.deps', 'plugins.architecture', 'plugins.simulate'],
            array_map(static fn ($operation): string => $operation->name, $plugin->operations()),
        );
    }

    /**
     * LA COSTURA: una línea base declarada en el contenedor tiene que llegar hasta el reporte.
     *
     * Las pruebas de {@see PluginInspection} demuestran que el reporte la usa bien cuando la recibe.
     * Ésta demuestra lo otro, que es donde se pierden las cosas: que el host la encuentre. Sin este
     * caso, la reparación de Q-P17-J podría estar completa y desconectada — que es exactamente la
     * forma en que el bucle del agente vivió instalado sin que nadie lo llamara.
     */
    public function testABaselineDeclaredInTheContainerReachesTheArchitectureReport(): void
    {
        $baseline = new class implements StateBaselineInterface {
            /** @return list<string>|null */
            public function enabledAtBaseline(): ?array
            {
                return ['AlgoQueYaNoEsta'];
            }

            public function baselineLabel(): string
            {
                return 'que empezó esta vuelta';
            }
        };

        $plugin = new PluginManagementPlugin($this->container([
            PluginRegistryInterface::class => new InMemoryPluginRegistry(),
            StateBaselineInterface::class => $baseline,
        ]));

        $arquitectura = null;
        foreach ($plugin->operations() as $operation) {
            if ($operation->name === 'plugins.architecture') {
                $arquitectura = ($operation->handler)([]);
            }
        }

        self::assertIsArray($arquitectura);
        self::assertIsArray($arquitectura['baseline'] ?? null, 'la línea base del contenedor no llegó al reporte');
        self::assertFalse($arquitectura['baseline']['unchanged']);
        self::assertSame(['AlgoQueYaNoEsta'], $arquitectura['baseline']['disabledSince']);
    }

    public function testWithNoRegistryItSaysWhatIsMissingInsteadOfOfferingNothing(): void
    {
        // An empty operation list would look exactly like a host that has no
        // plugins — the wiring mistake would be invisible.
        $plugin = new PluginManagementPlugin($this->container([]));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('needs a ' . PluginRegistryInterface::class);

        $plugin->operations();
    }

    public function testRunningItsWholeLifecycleLeavesTheStoreItManagesUntouched(): void
    {
        // This plugin manages the registry; it must never write to it as a
        // side effect of its own lifecycle. Uninstalling the manager is not
        // uninstalling everything it manages.
        $registry = new InMemoryPluginRegistry();
        $registry->register(new \Milpa\Plugin\Contracts\PluginRecord(
            name: 'MailPlugin',
            version: '1.0.0',
            author: 'Acme',
            site: 'https://example.com',
            type: 'Service',
            installed: true,
            enabled: true,
        ));
        $plugin = new PluginManagementPlugin($this->container([PluginRegistryInterface::class => $registry]));

        $plugin->boot();
        $plugin->install();
        $plugin->enable();
        $plugin->disable();
        $plugin->uninstall();

        self::assertSame(['MailPlugin'], array_map(
            static fn ($record): string => $record->name,
            $registry->installed(),
        ));
        self::assertTrue($registry->find('MailPlugin')?->enabled);
    }
}
