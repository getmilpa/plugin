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
use Milpa\Plugin\Contracts\PluginSchemaManagerInterface;
use Milpa\Plugin\PluginBase;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * The slim PluginBase: container plumbing plus the entity-schema pair, now
 * delegating to PluginSchemaManagerInterface with REAL FQCNs discovered from
 * the files themselves — no guessed namespaces, no var_dump, loud logs.
 */
final class PluginBaseTest extends TestCase
{
    /** @var list<string> */
    private array $logLines = [];

    /** @var array<string, object> */
    private array $services = [];

    private DIContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();
        $logLines = &$this->logLines;
        $logger = new class ($logLines) extends AbstractLogger {
            /** @param list<string> $lines */
            public function __construct(private array &$lines)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->lines[] = (string) $message;
            }
        };
        $this->services = [LoggerInterface::class => $logger];
        $services = &$this->services;

        $this->container = new class ($services) implements DIContainerInterface {
            /** @param array<string, object> $services */
            public function __construct(private array &$services)
            {
            }

            public function registerService(string $id, string|object $classOrInstance): void
            {
                if (\is_string($classOrInstance)) {
                    throw new \RuntimeException('instances only in this double');
                }
                $this->services[$id] = $classOrInstance;
            }

            public function get(string $id): mixed
            {
                return $this->services[$id] ?? throw new class ("not registered: {$id}") extends \RuntimeException implements NotFoundExceptionInterface {
                };
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

            public function getContainer(): ContainerInterface
            {
                return $this;
            }
        };
    }

    private function harness(): object
    {
        return new class ($this->container) extends PluginBase {
            public function callCreate(string $path): void
            {
                $this->createEntitiesFromPath($path);
            }

            public function callRemove(string $path): void
            {
                $this->removeEntitiesFromPath($path);
            }

            public function callLog(string $message): void
            {
                $this->log($message);
            }

            public function exposeContainer(): DIContainerInterface
            {
                return $this->getContainer();
            }

            /** @param array<string, object> $services */
            public function callRegisterServices(array $services): void
            {
                $this->registerServices($services);
            }

            public function callRegisterService(string $name, object $service): void
            {
                $this->registerService($name, $service);
            }

            public function callGetService(string $name): object
            {
                return $this->getService($name);
            }

            public function callTryGetService(string $name): ?object
            {
                return $this->tryGetService($name);
            }
        };
    }

    /** @return array{0: object, 1: string} the spy schema manager and a fixture dir */
    private function schemaFixture(): array
    {
        $spy = new class () implements PluginSchemaManagerInterface {
            /** @var list<list<string>> */
            public array $created = [];

            /** @var list<list<string>> */
            public array $dropped = [];

            public function createSchemaFor(array $entityClasses): void
            {
                $this->created[] = $entityClasses;
            }

            public function dropSchemaFor(array $entityClasses): void
            {
                $this->dropped[] = $entityClasses;
            }
        };
        $this->services[PluginSchemaManagerInterface::class] = $spy;

        $dir = sys_get_temp_dir() . '/milpa-base-entities-' . uniqid();
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/RealNote.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Milpa\Plugin\Tests\BaseFixtures;

            class RealNote
            {
            }
            PHP);
        // The class may already be loaded by an earlier test in this process
        // (each test writes its own tmp copy of the same fixture FQCN); the
        // discovery only needs the FILE in the scanned dir plus a loadable class.
        if (!class_exists('Milpa\\Plugin\\Tests\\BaseFixtures\\RealNote', false)) {
            require_once $dir . '/RealNote.php';
        }
        // A stray file whose declared class does NOT exist after require: the
        // discovery must skip it with a warning, never crash.
        file_put_contents($dir . '/Broken.php', "<?php\n// no class declaration at all\n");

        return [$spy, $dir];
    }

    /** The container plumbing surface the 14 plugins rely on. */
    public function testContainerPlumbingSurface(): void
    {
        $base = $this->harness();
        $service = new \stdClass();

        $base->exposeContainer()->registerService('svc', $service);
        $this->assertSame($service, $this->container->get('svc'));

        $base->callLog('hello from the base');
        $this->assertSame(['hello from the base'], $this->logLines);
    }

    public function testThePluginFacingServiceHelpersGoThroughTheContainer(): void
    {
        // These four are the whole container surface a plugin is meant to
        // touch. Reaching around them to the container in a test proves the
        // container works, not that the base class hands it what it asked for.
        $base = $this->harness();
        $one = new \stdClass();
        $two = new \stdClass();

        $base->callRegisterService('one', $one);
        $base->callRegisterServices(['two' => $two]);

        $this->assertSame($one, $base->callGetService('one'));
        $this->assertSame($two, $base->callGetService('two'));
        $this->assertSame($one, $base->callTryGetService('one'));
        $this->assertNull($base->callTryGetService('absent'), 'tryGet is the optional-dependency door: an absent service is null, not a throw.');
    }

    public function testAskingForAServiceThatIsNotThereThrows(): void
    {
        $this->expectException(NotFoundExceptionInterface::class);

        $this->harness()->callGetService('absent');
    }

    /** createEntitiesFromPath discovers REAL FQCNs and delegates to the schema port. */
    public function testCreateDiscoversRealFqcnsAndDelegates(): void
    {
        [$spy, $dir] = $this->schemaFixture();

        $this->harness()->callCreate($dir);

        $this->assertSame([['Milpa\\Plugin\\Tests\\BaseFixtures\\RealNote']], $spy->created);
        // The broken file produced a warning, not a crash and not a var_dump.
        $this->assertNotEmpty(array_filter($this->logLines, fn (string $l): bool => str_contains($l, 'Broken.php')));
    }

    /** removeEntitiesFromPath delegates the same discovered classes to dropSchemaFor. */
    public function testRemoveDelegatesToDrop(): void
    {
        [$spy, $dir] = $this->schemaFixture();

        $this->harness()->callRemove($dir);

        $this->assertSame([['Milpa\\Plugin\\Tests\\BaseFixtures\\RealNote']], $spy->dropped);
    }

    /** Without the schema port registered, the pair degrades to a loud warning. */
    public function testDegradesLoudlyWithoutTheSchemaPort(): void
    {
        $dir = sys_get_temp_dir() . '/milpa-base-noport-' . uniqid();
        mkdir($dir, 0777, true);

        $this->harness()->callCreate($dir);

        $this->assertNotEmpty(array_filter($this->logLines, fn (string $l): bool => str_contains($l, 'PluginSchemaManagerInterface')));
    }
}
