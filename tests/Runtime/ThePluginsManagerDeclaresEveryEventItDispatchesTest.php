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

use Milpa\Events\InterceptionSlot;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Event\DeclaredEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Plugin\Registry\InMemoryPluginRegistry;
use Milpa\Plugin\Runtime\ManagerConfig;
use Milpa\Plugin\Runtime\PluginsManager;
use Milpa\Plugins\BootProbePlugin\BootProbePlugin;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * The falsifier of greenhouse decisions/0228 for this package: the manager DECLARES every event it
 * DISPATCHES, to the dispatcher, once, and each declaration describes the payload the dispatch
 * really carries.
 *
 * A spy dispatcher implementing both contracts records what was declared and what was dispatched
 * while the REAL boot path runs (loadPlugins() over a plugin on disk). The expected names are
 * hardcoded here on purpose: a deleted or renamed declaration goes red against this list, not
 * against the constants it was built from. The control is the same path under a dispatcher that
 * does not declare at all.
 */
final class ThePluginsManagerDeclaresEveryEventItDispatchesTest extends TestCase
{
    /** The lifecycle events one boot dispatches, in the order it dispatches them. */
    private const EXPECTED = ['capability.resolved', 'plugin.booting', 'plugin.booted', 'kernel.booted'];

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/milpa_declared_events_' . uniqid();
        mkdir($this->tmp, 0755, true);
        file_put_contents($this->tmp . '/enabled_plugins.php', "<?php\nreturn ['BootProbe'];\n");
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->tmp);
        parent::tearDown();
    }

    public function testEveryNameTheBootDispatchesWasDeclaredAndTheDeclaredSetIsExactlyTheLifecycle(): void
    {
        $spy = new DeclaringSpyDispatcher();

        $this->boot($spy);

        $declaredNames = array_map(static fn (EventDeclaration $d): string => $d->name, $spy->declared());
        $this->assertSame(self::EXPECTED, $declaredNames, 'The declared set is the exact lifecycle, in dispatch order.');
        $this->assertSame([], array_values(array_diff($spy->dispatched(), $declaredNames)), 'A name was dispatched without being declared.');
        $this->assertSame(self::EXPECTED, $spy->dispatched(), 'The boot dispatches every declared event, and nothing it did not declare.');
    }

    public function testEachDeclarationDescribesThePayloadItsDispatchActuallyCarries(): void
    {
        $spy = new DeclaringSpyDispatcher();

        $this->boot($spy);

        $this->assertCount(4, $spy->declared());
        foreach ($spy->declared() as $declaration) {
            $name = $declaration->name;
            $this->assertArrayHasKey($name, $spy->payloads, "«{$name}» was declared but never dispatched on this path.");
            $payload = $spy->payloads[$name];

            $this->assertSame(PluginsManager::class, $declaration->dispatchedBy, "«{$name}» names a dispatcher other than the class that dispatches it.");
            $this->assertNotSame('', trim($declaration->when), "«{$name}» says nothing about when it fires.");
            $this->assertArrayHasKey($declaration->subjectKey, $payload, "«{$name}» declares a subject key its payload does not carry.");
            $this->assertNotNull($declaration->subjectType, "«{$name}» declares no subject type.");
            $this->assertInstanceOf($declaration->subjectType, $payload[$declaration->subjectKey], "«{$name}» declares a subject type its payload does not carry.");
            $this->assertFalse($declaration->mutable, "«{$name}» carries a read-only lifecycle value object; subscribers do not change it.");

            if ($declaration->interceptable) {
                $this->assertInstanceOf(InterceptionSlot::class, $payload['slot'] ?? null, "«{$name}» is declared interceptable but carries no slot.");
            } else {
                $this->assertArrayNotHasKey('slot', $payload, "«{$name}» carries a slot but is not declared interceptable.");
            }
        }
    }

    public function testTheDeclarationHappensOnceHoweverManyTimesTheDispatcherIsResolved(): void
    {
        $spy = new DeclaringSpyDispatcher();

        $this->boot($spy);

        $this->assertCount(4, $spy->dispatched(), 'Sanity: the boot resolved the dispatcher more than once.');
        $this->assertSame(1, $spy->declareCalls, 'The package declares its events once, not once per dispatch.');
    }

    public function testControlADispatcherThatDoesNotDeclareBootsTheSamePathUntouched(): void
    {
        $plain = new PlainSpyDispatcher();

        $manager = $this->boot($plain);

        $this->assertSame(self::EXPECTED, $plain->dispatched, 'The plain dispatcher sees the same dispatches, in the same order.');
        $this->assertInstanceOf(BootProbePlugin::class, $manager->getPlugin('BootProbe'), 'The plugin booted under a dispatcher that declares nothing.');
    }

    /**
     * Runs the manager's real boot path with $dispatcher registered where the manager looks for it.
     */
    private function boot(MilpaEventDispatcherInterface $dispatcher): PluginsManager
    {
        $manager = new PluginsManager(
            new ServiceMapContainer([
                LoggerInterface::class => new NullLogger(),
                MilpaEventDispatcherInterface::class => $dispatcher,
            ]),
            new InMemoryPluginRegistry(),
            new ManagerConfig(
                cacheDir: $this->tmp,
                hostManifestPath: null,
                devMode: true,
                environment: 'CLI',
            ),
        );
        $manager->addPluginPath(\dirname(__DIR__) . '/Fixtures/Plugins');
        $manager->loadPlugins();

        return $manager;
    }
}

/**
 * A dispatcher that implements both contracts and records what it was told: the declarations
 * (first one per name wins, as the contract says), every name dispatched, and the last payload
 * dispatched under each name.
 */
final class DeclaringSpyDispatcher implements MilpaEventDispatcherInterface, DeclaredEvents
{
    public int $declareCalls = 0;

    /** @var array<string, array<string, mixed>> */
    public array $payloads = [];

    /** @var list<EventDeclaration> */
    private array $declared = [];

    /** @var list<string> */
    private array $dispatched = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $eventName, array $payload = [], bool $async = false): void
    {
        if (!\in_array($eventName, $this->dispatched, true)) {
            $this->dispatched[] = $eventName;
        }
        $this->payloads[$eventName] = $payload;
    }

    public function subscribe(string $eventName, callable $handler, int $priority = 0): void
    {
    }

    /**
     * @return list<callable>
     */
    public function getSubscribers(string $eventName): array
    {
        return [];
    }

    public function hasSubscribers(string $eventName): bool
    {
        return false;
    }

    public function declare(EventDeclaration ...$events): void
    {
        $this->declareCalls++;
        foreach ($events as $event) {
            foreach ($this->declared as $known) {
                if ($known->name === $event->name) {
                    continue 2;
                }
            }
            $this->declared[] = $event;
        }
    }

    /**
     * @return list<EventDeclaration>
     */
    public function declared(): array
    {
        return $this->declared;
    }

    /**
     * @return list<string>
     */
    public function dispatched(): array
    {
        return $this->dispatched;
    }
}

/**
 * The control: a dispatcher that implements only the dispatching contract and records the names it
 * was handed. It cannot be declared to, and the package must not care.
 */
final class PlainSpyDispatcher implements MilpaEventDispatcherInterface
{
    /** @var list<string> */
    public array $dispatched = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $eventName, array $payload = [], bool $async = false): void
    {
        if (!\in_array($eventName, $this->dispatched, true)) {
            $this->dispatched[] = $eventName;
        }
    }

    public function subscribe(string $eventName, callable $handler, int $priority = 0): void
    {
    }

    /**
     * @return list<callable>
     */
    public function getSubscribers(string $eventName): array
    {
        return [];
    }

    public function hasSubscribers(string $eventName): bool
    {
        return false;
    }
}

/**
 * A container that serves a map of services and accepts the plugin instances the manager registers
 * while it boots — enough for the real boot path, nothing more.
 */
final class ServiceMapContainer implements DIContainerInterface
{
    /**
     * @param array<string, object> $services
     */
    public function __construct(private array $services)
    {
    }

    public function registerService(string $id, string|object $classOrInstance): void
    {
        $this->services[$id] = \is_string($classOrInstance) ? new $classOrInstance($this) : $classOrInstance;
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
}
