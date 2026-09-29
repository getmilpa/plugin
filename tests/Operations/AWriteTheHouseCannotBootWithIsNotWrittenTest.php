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

use Milpa\Command\Operation;
use Milpa\Plugin\Contracts\BootWitnessInterface;
use Milpa\Plugin\Contracts\PluginRecord;
use Milpa\Plugin\Operations\PluginManagementPlugin;
use Milpa\Plugin\Operations\PluginOperations;
use Milpa\Plugin\Registry\FilePluginRegistry;
use Milpa\Plugin\Registry\InMemoryPluginRegistry;
use PHPUnit\Framework\TestCase;

/**
 * What milpa/plugin owes the house's witness (greenhouse decisions/0515): the exact bytes it would write, whether
 * the write is recovery, and a refusal carried back as the operation's own answer.
 *
 * The rule itself — boot a copy, let recovery through, put the old bytes back — lives with the host's witness
 * (milpa/app-runtime's HouseBootWitness, where it is measured with real boots). Here the witness is a recorder
 * that says yes or no, so what is pinned is this package's half: WHAT it asks, and what it does with the answer.
 */
final class AWriteTheHouseCannotBootWithIsNotWrittenTest extends TestCase
{
    private string $root;

    /** @var list<array{writes: array<string, string>, recovery: bool}> */
    private array $asked = [];

    /** The answer the stand-in witness gives: null lets the write through. */
    private ?string $refuse = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/milpa-witness-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/config', 0o775, true);
        mkdir($this->root . '/storage', 0o775, true);
        file_put_contents($this->root . '/config/plugins.php', "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    Milpa\\Plugin\\Operations\\PluginManagementPlugin::class,\n];\n");
        mkdir($this->root . '/src/Plugins/BrokenPlugin', 0o775, true);
        file_put_contents($this->root . '/src/Plugins/BrokenPlugin/BrokenPlugin.php', "<?php\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    private function witness(): BootWitnessInterface
    {
        $test = $this;

        return new class ($test) implements BootWitnessInterface {
            public function __construct(private readonly AWriteTheHouseCannotBootWithIsNotWrittenTest $test)
            {
            }

            public function writeIfItBoots(array $writes, callable $commit, bool $recovery = false, array $deletes = []): array
            {
                return $this->test->answer($writes, $commit, $recovery);
            }
        };
    }

    /**
     * @param array<string, string> $writes
     * @param callable(): void      $commit
     *
     * @return array{refused: ?string, said: array<string, mixed>}
     */
    public function answer(array $writes, callable $commit, bool $recovery): array
    {
        $this->asked[] = ['writes' => $writes, 'recovery' => $recovery];
        if ($this->refuse !== null) {
            return ['refused' => $this->refuse, 'said' => ['unwritten' => array_keys($writes), 'house_boots' => true]];
        }
        $commit();

        return ['refused' => null, 'said' => ['house_boots' => true]];
    }

    private function record(string $name, bool $enabled): PluginRecord
    {
        return new PluginRecord(name: $name, version: '1.0.0', author: 'Acme', site: 'https://example.com', type: 'Service', installed: true, enabled: $enabled, source: 'local');
    }

    /** @return array<string, Operation> */
    private function operations(?BootWitnessInterface $witness, mixed $registry = null): array
    {
        $registry ??= new FilePluginRegistry($this->root . '/storage/plugins.json');
        $ops = [];
        foreach ((new PluginOperations($registry, null, [], null, $this->root, null, $witness))->operations() as $op) {
            $ops[$op->name] = $op;
        }

        return $ops;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function call(string $name, array $input, ?BootWitnessInterface $witness): array
    {
        /** @var array<string, mixed> $r */
        $r = ($this->operations($witness)[$name]->handler)($input);

        return $r;
    }

    private function store(): string
    {
        return is_file($this->root . '/storage/plugins.json') ? (string) file_get_contents($this->root . '/storage/plugins.json') : '(absent)';
    }

    public function testRegisteringShowsTheWitnessTheListItWouldWriteAndWritesNothingWhenRefused(): void
    {
        $before = (string) file_get_contents($this->root . '/config/plugins.php');
        $this->refuse = 'The house does not boot with this change: BrokenPlugin contains 1 abstract method. Nothing was written.';

        $r = $this->call('plugins.register', ['name' => 'BrokenPlugin'], $this->witness());

        self::assertFalse($r['ok']);
        self::assertSame($this->refuse, $r['error'], 'the witness\'s sentence is the answer');
        self::assertSame(['config/plugins.php'], $r['unwritten']);
        self::assertSame($before, file_get_contents($this->root . '/config/plugins.php'));
        self::assertCount(1, $this->asked);
        self::assertSame(['config/plugins.php'], array_keys($this->asked[0]['writes']));
        self::assertStringContainsString('BrokenPlugin::class', $this->asked[0]['writes']['config/plugins.php'], 'the bytes that would land');
        self::assertFalse($this->asked[0]['recovery']);
    }

    public function testRegisteringWritesThroughTheWitnessAndSaysWhatItSaid(): void
    {
        $r = $this->call('plugins.register', ['name' => 'BrokenPlugin'], $this->witness());

        self::assertTrue($r['ok']);
        self::assertTrue($r['house_boots']);
        self::assertSame($this->asked[0]['writes']['config/plugins.php'], file_get_contents($this->root . '/config/plugins.php'), 'what was shown is what landed');
    }

    /** A class that does not compile is never loaded in THIS process: the copy is asked before the graph check reads it. */
    public function testTheCopyIsAskedBeforeTheGraphCheckLoadsTheClass(): void
    {
        $safety = new RecordingSafety('never reached');
        $this->refuse = 'The house does not boot with this change: BrokenPlugin contains 1 abstract method.';

        $r = ($this->operationsWith($safety)['plugins.register']->handler)(['name' => 'BrokenPlugin']);

        self::assertFalse($r['ok']);
        self::assertSame($this->refuse, $r['error']);
        self::assertSame([], $safety->asked, 'the metadata of a class that may not compile is not read here');
    }

    public function testAGraphTheCopyBootsWithIsStillJudgedAndNothingIsWritten(): void
    {
        $before = (string) file_get_contents($this->root . '/config/plugins.php');
        $safety = new RecordingSafety('BrokenPlugin requires "data.repository" and nobody provides it.');

        $r = ($this->operationsWith($safety)['plugins.register']->handler)(['name' => 'BrokenPlugin']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('data.repository', (string) $r['error']);
        self::assertSame(['App\\Plugins\\BrokenPlugin\\BrokenPlugin'], $safety->asked);
        self::assertSame($before, file_get_contents($this->root . '/config/plugins.php'));
    }

    /** @return array<string, Operation> */
    private function operationsWith(RecordingSafety $safety): array
    {
        $ops = [];
        foreach ((new PluginOperations(new FilePluginRegistry($this->root . '/storage/plugins.json'), null, [], $safety, $this->root, null, $this->witness()))->operations() as $op) {
            $ops[$op->name] = $op;
        }

        return $ops;
    }

    public function testWithoutAWitnessRegisteringIsWhatItWas(): void
    {
        $r = $this->call('plugins.register', ['name' => 'BrokenPlugin'], null);

        self::assertTrue($r['ok']);
        self::assertArrayNotHasKey('house_boots', $r, 'nothing claims it booted');
        self::assertStringContainsString('BrokenPlugin::class', (string) file_get_contents($this->root . '/config/plugins.php'));
    }

    public function testEnablingShowsTheWitnessTheRegistryAsItWouldBe(): void
    {
        (new FilePluginRegistry($this->root . '/storage/plugins.json'))->register($this->record('Thing', false));
        $before = $this->store();
        $this->refuse = 'The house does not boot with this change: Thing cannot start.';

        try {
            $this->call('plugins.enable', ['name' => 'Thing'], $this->witness());
            self::fail('a refused enable throws, as every refusal of a toggle does');
        } catch (\RuntimeException $e) {
            self::assertSame($this->refuse . ' unwritten: storage/plugins.json.', $e->getMessage(), 'the witness\'s sentence, and the path it left unwritten');
        }
        self::assertSame($before, $this->store(), 'the live registry never moved');
        /** @var array{plugins: list<array{name: string, enabled: bool}>} $rehearsed */
        $rehearsed = json_decode($this->asked[0]['writes']['storage/plugins.json'], true);
        self::assertTrue($rehearsed['plugins'][0]['enabled'], 'the rehearsal carries the switch');
        self::assertSame([], glob(sys_get_temp_dir() . '/milpa-registry-*') ?: [], 'and leaves no scratch behind');
    }

    public function testEnablingLandsWhatWasRehearsed(): void
    {
        (new FilePluginRegistry($this->root . '/storage/plugins.json'))->register($this->record('Thing', false));

        $r = $this->call('plugins.enable', ['name' => 'Thing'], $this->witness());

        self::assertTrue($r['enabled']);
        self::assertTrue($r['house_boots']);
        self::assertSame($this->asked[0]['writes']['storage/plugins.json'], $this->store());
    }

    /** The skeleton's own wiring: `config/boot.php` names the store as `__DIR__ . '/../storage/plugins.json'`. */
    public function testARegistryNamedThroughDotDotIsTheSameFileUnderTheRoot(): void
    {
        $registry = new FilePluginRegistry($this->root . '/config/../storage/plugins.json');
        $registry->register($this->record('Thing', false));

        $r = ($this->operations($this->witness(), $registry)['plugins.enable']->handler)(['name' => 'Thing']);

        self::assertTrue($r['house_boots']);
        self::assertSame(['storage/plugins.json'], array_keys($this->asked[0]['writes']), 'a path the witness can build a copy with');
    }

    public function testARegistryWithNoFileYetIsRehearsedFromNothing(): void
    {
        $ops = [];
        foreach ((new PluginOperations(new FilePluginRegistry($this->root . '/storage/plugins.json'), null, [\Milpa\Plugin\Tests\Fixtures\ApiFixturePlugin::class], null, $this->root, null, $this->witness()))->operations() as $op) {
            $ops[$op->name] = $op;
        }
        /** @var array{plugins: list<array{name: string}>} $listed */
        $listed = ($ops['plugins.list']->handler)([]);
        self::assertNotSame([], $listed['plugins']);

        ($ops['plugins.disable-unsafe']->handler)(['name' => $listed['plugins'][0]['name']]);

        self::assertStringContainsString('"enabled": false', $this->asked[0]['writes']['storage/plugins.json']);
        self::assertFileExists($this->root . '/storage/plugins.json');
    }

    public function testDisableUnsafeIsRecoveryAndPlainDisableIsNot(): void
    {
        $registry = new FilePluginRegistry($this->root . '/storage/plugins.json');
        $registry->register($this->record('Thing', true));

        $this->call('plugins.disable-unsafe', ['name' => 'Thing'], $this->witness());
        $this->call('plugins.enable', ['name' => 'Thing'], $this->witness());

        self::assertTrue($this->asked[0]['recovery'], 'the way back');
        self::assertFalse($this->asked[1]['recovery']);
    }

    public function testARegistryThatIsNotAFileUnderTheRootIsWrittenAsBeforeAndClaimsNothing(): void
    {
        $registry = new InMemoryPluginRegistry();
        $registry->register($this->record('Thing', false));

        $r = ($this->operations($this->witness(), $registry)['plugins.enable']->handler)(['name' => 'Thing']);

        self::assertTrue($r['enabled']);
        self::assertArrayNotHasKey('house_boots', $r);
        self::assertSame([], $this->asked, 'nothing to rehearse, so nothing is claimed');
    }

    public function testAFileRegistryOutsideTheRootIsNotRehearsed(): void
    {
        $elsewhere = sys_get_temp_dir() . '/milpa-elsewhere-' . bin2hex(random_bytes(4)) . '.json';
        $registry = new FilePluginRegistry($elsewhere);
        $registry->register($this->record('Thing', false));

        $r = ($this->operations($this->witness(), $registry)['plugins.enable']->handler)(['name' => 'Thing']);
        @unlink($elsewhere);

        self::assertTrue($r['enabled']);
        self::assertSame([], $this->asked);
    }

    public function testTheFamilysWitnessIsFoundByNameOnlyWhenItIsOne(): void
    {
        self::assertNull(PluginManagementPlugin::hostWitness($this->root), 'no milpa/app-runtime here');
        self::assertNull(PluginManagementPlugin::hostWitness($this->root, \stdClass::class), 'a class that is not a witness is not one');
        $witness = PluginManagementPlugin::hostWitness($this->root, NamedWitness::class);
        self::assertInstanceOf(NamedWitness::class, $witness);
        self::assertSame($this->root, $witness->root);
    }
}

/** A witness the host would ship: built from the app root alone. */
final class NamedWitness implements BootWitnessInterface
{
    public function __construct(public readonly string $root)
    {
    }

    public function writeIfItBoots(array $writes, callable $commit, bool $recovery = false, array $deletes = []): array
    {
        $commit();

        return ['refused' => null, 'said' => []];
    }
}

/** A graph check that answers what it was told to, and remembers which classes it was asked about. */
final class RecordingSafety implements \Milpa\Plugin\Contracts\ActivationSafetyInterface
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(private readonly ?string $with)
    {
    }

    public function blockingReasonWithout(string $pluginName): ?string
    {
        return null;
    }

    public function blockingReasonWith(string $newPluginClass): ?string
    {
        $this->asked[] = $newPluginClass;

        return $this->with;
    }
}
