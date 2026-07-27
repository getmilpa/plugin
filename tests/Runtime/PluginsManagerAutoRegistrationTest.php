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

use Milpa\Attributes\PluginMetadata;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Event\EventSubscriberInterface;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Interfaces\Tooling\ToolProviderInterface;
use Milpa\Interfaces\Tooling\ToolRegistryInterface;
use Milpa\Plugin\Registry\InMemoryPluginRegistry;
use Milpa\Plugin\Runtime\ManagerConfig;
use Milpa\Plugin\Runtime\PluginsManager;
use Milpa\ValueObjects\Tooling\ToolOptions;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * What a plugin gets for free at boot: its tools land in the tool registry and
 * its event subscriptions land in the dispatcher, without the plugin wiring
 * either by hand.
 *
 * Both hooks are gated — tools only load in the environment the plugin's type
 * declares, and both are skipped outright when the host does not provide the
 * collaborator. Those gates are the interesting part: a plugin whose tools
 * silently never register looks exactly like a plugin whose tools are broken.
 */
final class PluginsManagerAutoRegistrationTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/milpa_manager_autoreg_' . uniqid();
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmp);
        parent::tearDown();
    }

    /**
     * A container serving exactly the ids in $services and nothing else.
     *
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

    /**
     * @param array<string, object> $services
     */
    private function manager(array $services, string $environment = 'CLI'): PluginsManager
    {
        // El logger sale del contenedor, no del constructor: PluginsManager lo
        // resuelve en su __construct y lo asigna a una propiedad no-nullable.
        $services[LoggerInterface::class] = new NullLogger();

        return new PluginsManager(
            $this->container($services),
            new InMemoryPluginRegistry(),
            new ManagerConfig(
                cacheDir: $this->tmp,
                hostManifestPath: null,
                devMode: true,
                environment: $environment,
            ),
        );
    }

    /**
     * Calls one of the manager's private auto-registration hooks.
     */
    private function invoke(PluginsManager $manager, string $method, object $plugin): void
    {
        $reflection = new \ReflectionMethod($manager, $method);
        $reflection->invoke($manager, $plugin, $plugin::class);
    }

    // =========================================================================
    // tools
    // =========================================================================

    public function testAServicePluginsToolsAreRegisteredInEveryEnvironment(): void
    {
        $registry = new RecordingToolRegistry();
        $manager = $this->manager([ToolRegistryInterface::class => $registry], 'Web');

        $this->invoke($manager, 'registerPluginTools', new ServiceToolPlugin());

        $this->assertSame(['service.ping'], $registry->registered);
    }

    public function testACliPluginsToolsDoNotLoadInAWebProcess(): void
    {
        // The gate exists so a CLI-only tool never shows up on a web request.
        // Without it, the tool is reachable from an environment its plugin
        // never intended to serve.
        $registry = new RecordingToolRegistry();
        $manager = $this->manager([ToolRegistryInterface::class => $registry], 'Web');

        $this->invoke($manager, 'registerPluginTools', new CliToolPlugin());

        $this->assertSame([], $registry->registered);
    }

    public function testACliPluginsToolsDoLoadInACliProcess(): void
    {
        $registry = new RecordingToolRegistry();
        $manager = $this->manager([ToolRegistryInterface::class => $registry], 'CLI');

        $this->invoke($manager, 'registerPluginTools', new CliToolPlugin());

        $this->assertSame(['cli.ping'], $registry->registered);
    }

    public function testWithNoToolRegistryInTheHostToolRegistrationIsSkippedRatherThanFatal(): void
    {
        // A host that does not install the tool runtime is a normal host, not
        // a broken one: a plugin that also offers tools still boots there.
        $manager = $this->manager([], 'CLI');

        $this->invoke($manager, 'registerPluginTools', new ServiceToolPlugin());

        $this->addToAssertionCount(1);
    }

    public function testAPluginThatThrowsWhileRegisteringItsToolsDoesNotTakeTheBootDown(): void
    {
        $manager = $this->manager([ToolRegistryInterface::class => new RecordingToolRegistry()], 'CLI');

        $this->invoke($manager, 'registerPluginTools', new ThrowingToolPlugin());

        $this->addToAssertionCount(1);
    }

    public function testAPluginThatOffersNoToolsIsLeftAlone(): void
    {
        $registry = new RecordingToolRegistry();
        $manager = $this->manager([ToolRegistryInterface::class => $registry], 'CLI');

        $this->invoke($manager, 'registerPluginTools', new PlainPlugin());

        $this->assertSame([], $registry->registered);
    }

    // =========================================================================
    // event subscriptions
    // =========================================================================

    public function testAPluginsDeclaredSubscriptionsReachTheDispatcherWithTheirPriority(): void
    {
        $dispatcher = new RecordingDispatcher();
        $manager = $this->manager([MilpaEventDispatcherInterface::class => $dispatcher]);

        $this->invoke($manager, 'registerPluginEventSubscriptions', new SubscribingPlugin());

        $this->assertSame([['user.created', 10], ['user.deleted', 0]], $dispatcher->subscriptions);
    }

    public function testASubscriptionIsBoundToThePluginInstanceThatDeclaredIt(): void
    {
        // getSubscribedEvents() is static, but the handler has to run on the
        // booted instance — otherwise the listener fires against a plugin that
        // never received its container.
        $dispatcher = new RecordingDispatcher();
        $manager = $this->manager([MilpaEventDispatcherInterface::class => $dispatcher]);
        $plugin = new SubscribingPlugin();

        $this->invoke($manager, 'registerPluginEventSubscriptions', $plugin);
        ($dispatcher->handlers[0])('user.created', ['id' => 7]);

        $this->assertSame([['user.created', ['id' => 7]]], $plugin->handled);
    }

    public function testASubscriptionNamingAMethodThePluginDoesNotHaveIsSkipped(): void
    {
        $dispatcher = new RecordingDispatcher();
        $manager = $this->manager([MilpaEventDispatcherInterface::class => $dispatcher]);

        $this->invoke($manager, 'registerPluginEventSubscriptions', new BadSubscriptionPlugin());

        $this->assertSame([], $dispatcher->subscriptions, 'A typo in a handler name must not register a listener that fatals when the event fires.');
    }

    public function testAPluginThatSubscribesToNothingIsLeftAlone(): void
    {
        $dispatcher = new RecordingDispatcher();
        $manager = $this->manager([MilpaEventDispatcherInterface::class => $dispatcher]);

        $this->invoke($manager, 'registerPluginEventSubscriptions', new PlainPlugin());

        $this->assertSame([], $dispatcher->subscriptions);
    }
}

/**
 * A tool registry that records the names it was handed.
 */
final class RecordingToolRegistry implements ToolRegistryInterface
{
    /** @var list<string> */
    public array $registered = [];

    /**
     * @param array<string, mixed> $inputSchema
     */
    public function register(string $name, string $description, array $inputSchema, callable $callback, ?ToolOptions $options = null): void
    {
        $this->registered[] = $name;
    }
}

/**
 * A dispatcher that records what was subscribed and keeps the handlers.
 */
final class RecordingDispatcher implements MilpaEventDispatcherInterface
{
    /** @var list<array{string, int}> */
    public array $subscriptions = [];

    /** @var list<callable> */
    public array $handlers = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $eventName, array $payload = [], bool $async = false): void
    {
    }

    public function subscribe(string $eventName, callable $handler, int $priority = 0): void
    {
        $this->subscriptions[] = [$eventName, $priority];
        $this->handlers[] = $handler;
    }

    /**
     * @return list<callable>
     */
    public function getSubscribers(string $eventName): array
    {
        return $this->handlers;
    }

    public function hasSubscribers(string $eventName): bool
    {
        return $this->handlers !== [];
    }
}

/**
 * The plugin lifecycle methods every fixture below needs and none of them use.
 */
trait InertPluginLifecycle
{
    public function __construct(?DIContainerInterface $container = null)
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
}

/** A plugin that offers neither tools nor subscriptions. */
#[PluginMetadata(version: '1.0.0', author: 'TeamX', site: 'https://teamx.agency', name: 'Plain', type: 'Service')]
final class PlainPlugin implements PluginInterface
{
    use InertPluginLifecycle;
}

/** A Service-typed tool provider: loads everywhere. */
#[PluginMetadata(version: '1.0.0', author: 'TeamX', site: 'https://teamx.agency', name: 'ServiceTool', type: 'Service')]
final class ServiceToolPlugin implements PluginInterface, ToolProviderInterface
{
    use InertPluginLifecycle;

    public function registerTools(ToolRegistryInterface $registry): void
    {
        $registry->register('service.ping', 'pong', [], static fn (): string => 'pong');
    }

    /**
     * @return array<string, string>
     */
    public function getPromptSections(): array
    {
        return [];
    }
}

/** A CLI-typed tool provider: loads only in a CLI process. */
#[PluginMetadata(version: '1.0.0', author: 'TeamX', site: 'https://teamx.agency', name: 'CliTool', type: 'CLI')]
final class CliToolPlugin implements PluginInterface, ToolProviderInterface
{
    use InertPluginLifecycle;

    public function registerTools(ToolRegistryInterface $registry): void
    {
        $registry->register('cli.ping', 'pong', [], static fn (): string => 'pong');
    }

    /**
     * @return array<string, string>
     */
    public function getPromptSections(): array
    {
        return [];
    }
}

/** A tool provider whose registration blows up. */
#[PluginMetadata(version: '1.0.0', author: 'TeamX', site: 'https://teamx.agency', name: 'ThrowingTool', type: 'Service')]
final class ThrowingToolPlugin implements PluginInterface, ToolProviderInterface
{
    use InertPluginLifecycle;

    public function registerTools(ToolRegistryInterface $registry): void
    {
        throw new \RuntimeException('the tool runtime is misconfigured');
    }

    /**
     * @return array<string, string>
     */
    public function getPromptSections(): array
    {
        return [];
    }
}

/** A plugin with two subscriptions, one of them prioritised. */
#[PluginMetadata(version: '1.0.0', author: 'TeamX', site: 'https://teamx.agency', name: 'Subscribing', type: 'Service')]
final class SubscribingPlugin implements PluginInterface, EventSubscriberInterface
{
    use InertPluginLifecycle;

    /** @var list<array{string, array<string, mixed>}> */
    public array $handled = [];

    /**
     * @return array<string, array{method: string, priority?: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'user.created' => ['method' => 'onCreated', 'priority' => 10],
            'user.deleted' => ['method' => 'onDeleted'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function onCreated(string $event, array $payload): void
    {
        $this->handled[] = [$event, $payload];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function onDeleted(string $event, array $payload): void
    {
        $this->handled[] = [$event, $payload];
    }
}

/** A plugin whose subscription names a method it does not have. */
#[PluginMetadata(version: '1.0.0', author: 'TeamX', site: 'https://teamx.agency', name: 'BadSubscription', type: 'Service')]
final class BadSubscriptionPlugin implements PluginInterface, EventSubscriberInterface
{
    use InertPluginLifecycle;

    /**
     * @return array<string, array{method: string, priority?: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return ['user.created' => ['method' => 'onCraeted']];
    }
}
