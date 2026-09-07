<?php

/**
 * This file is part of Milpa Plugin — the plugin system of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/plugin
 */

declare(strict_types=1);

namespace Milpa\Plugin\Tests\Operations;

use Milpa\Command\Effect\Reversibility;
use Milpa\Plugin\Contracts\ActivationSafetyInterface;
use Milpa\Command\Operation;
use Milpa\Plugin\Operations\PluginOperations;
use Milpa\Plugin\Contracts\PluginRecord;
use Milpa\Plugin\Registry\InMemoryPluginRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * THE REVERSAL FALSIFIER — greenhouse `.milpa/promises/reversal-contract.yaml`.
 *
 * An operation that claims `Reversibility::Guaranteed` buys lower scrutiny with a promise: a tested
 * inverse exists. Until now the house asked for that proof and accepted a note. This runs it.
 *
 * The shape, and every part of it matters:
 *
 *   1. the forward operation runs, and its POSTCONDITION holds;
 *   2. the inverse is taken from what the forward operation DECLARED — never from what this test knows;
 *   3. the inverse is resolved by IDENTITY against the table, the way a gate would find it;
 *   4. it runs;
 *   5. the forward operation's postcondition is now FALSE, and its preconditions hold again.
 *
 * DEPTH 1, on purpose (the promise says so): this proves A's promise, not B's. The operation named as a
 * rollback declares its own reversibility honestly and is exempt from needing one — the cycle is allowed,
 * never required.
 *
 * What «the world came back» means here is NOT byte-identical state: running the inverse ADDS facts, so
 * nothing is ever identical by construction. It means the DECLARED consequence is gone.
 */
#[CoversClass(PluginOperations::class)]
final class TheReversalFalsifierTest extends TestCase
{
    private InMemoryPluginRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new InMemoryPluginRegistry();
        $this->registry->register(new PluginRecord(
            name: 'Acme',
            version: '1.0.0',
            author: 'Acme',
            site: 'https://example.com',
            type: 'Service',
            installed: true,
            enabled: false,
            source: 'local',
        ));
    }

    /** F — the promise of `plugins.enable` survives being run and undone. */
    public function testEnableIsUndoneByTheOperationItNames(): void
    {
        $table = $this->table();
        $forward = $table['plugins.enable'];

        self::assertSame(Reversibility::Guaranteed, $forward->effects?->reversibility, 'it claims the discount');
        self::assertFalse($this->postconditionOfEnableHolds(), 'and it has not run yet');

        ($forward->handler)(['name' => 'Acme']);
        self::assertTrue($this->postconditionOfEnableHolds(), 'the forward operation did what it declared');

        // TAKEN FROM THE DECLARATION, never from what this test happens to know — and resolved by
        // IDENTITY, the way a gate finds an act however a surface spells it.
        $inverse = $this->resolve($forward, $table);
        self::assertNotNull($inverse, 'the operation it named is on this table');

        ($inverse->handler)(['name' => 'Acme']);

        self::assertFalse($this->postconditionOfEnableHolds(), 'the declared consequence is gone');
        self::assertTrue($this->preconditionOfEnableHolds(), 'and the forward operation can be asked again');
    }

    /** The same, the other way: these two ARE each other's inverse, so the proof runs in both directions. */
    public function testDisableIsUndoneByTheOperationItNames(): void
    {
        $table = $this->table();
        ($table['plugins.enable']->handler)(['name' => 'Acme']);

        $forward = $table['plugins.disable'];
        ($forward->handler)(['name' => 'Acme']);
        self::assertFalse($this->postconditionOfEnableHolds(), 'the plugin is off: `plugins.disable` did its work');

        ($this->resolve($forward, $table)?->handler)(['name' => 'Acme']);

        self::assertTrue($this->postconditionOfEnableHolds(), 'and its declared inverse brought it back');
    }

    /**
     * THE POSITIVE CONTROL, and without it the test above proves nothing.
     *
     * If the assertion «the declared consequence is gone» passed whether or not the inverse ran, it
     * would be measuring the assertion, not the reversal. Running the forward operation and NOT the
     * inverse must leave the postcondition TRUE.
     */
    public function testWithoutRunningTheInverseTheConsequenceStays(): void
    {
        $table = $this->table();
        ($table['plugins.enable']->handler)(['name' => 'Acme']);

        self::assertTrue($this->postconditionOfEnableHolds(), 'nothing undid it, so it is still done');
    }

    /**
     * And the promise is not a sentence: what backs it is a name this table answers to, in the identity
     * the gate uses — which is the whole difference between «we can undo it» and a rollback contract.
     */
    public function testTheContractIsANameThisTableAnswersTo(): void
    {
        $table = $this->table();

        foreach (['plugins.enable' => 'plugins.disable', 'plugins.disable' => 'plugins.enable'] as $name => $expected) {
            $operation = $table[$name];
            self::assertTrue(
                $operation->effects?->rollbackOperation()?->is($expected),
                $name . ' names ' . $expected . ' as its inverse',
            );
            self::assertNotNull($this->resolve($operation, $table), $name . '\'s inverse is on the table');
        }
    }

    /**
     * WHAT THE FALSIFIER FOUND ON ITS FIRST RUN, and it is the point of running one.
     *
     * `plugins.enable` claims `Guaranteed` and names `plugins.disable`. That inverse is on the table, it
     * is a real operation, and the profile guard and the table verifier both pass — and on a host that
     * wires no {@see ActivationSafetyInterface} it REFUSES TO RUN, because nobody can check whether
     * turning the plugin off would leave the capability graph without a provider. The way out is
     * `plugins.disable-unsafe`, which demands explicit confirmation and is not offered to an agent.
     *
     * So the promise is CONDITIONAL ON THE HOST, and nothing in the declaration says so. The enum's own
     * docblock already asked for it — «a rollback contract exists, names the inverse operation, and THE
     * AUTHORITY TO RUN IT IS AVAILABLE» — and the third clause is the one nothing checks.
     *
     * Recorded here as a test rather than fixed here: giving `EffectProfile` a way to declare that a
     * guarantee depends on a collaborator is new vocabulary, and new vocabulary is decided in an acta,
     * not slipped into a measuring run (greenhouse evidence/0554).
     */
    public function testAHostWithoutTheSafetyEvaluatorCannotKeepThePromise(): void
    {
        $table = $this->table(withSafety: false);
        ($table['plugins.enable']->handler)(['name' => 'Acme']);
        self::assertTrue($this->postconditionOfEnableHolds());

        $inverse = $this->resolve($table['plugins.enable'], $table);
        self::assertNotNull($inverse, 'the inverse is declared, named and on the table');

        try {
            ($inverse->handler)(['name' => 'Acme']);
            self::fail('the inverse ran on a host that cannot evaluate whether it is safe');
        } catch (\RuntimeException $refused) {
            self::assertStringContainsString('MILPA_PLUGIN_SAFETY_UNAVAILABLE', $refused->getMessage());
        }

        self::assertTrue(
            $this->postconditionOfEnableHolds(),
            'and the consequence stays: on this host the guarantee cannot be kept by the operation it names',
        );
    }

    /**
     * The postcondition `plugins.enable` declares, read from the world through its own declared evidence:
     * «the registry entry for that plugin reports enabled = true».
     */
    private function postconditionOfEnableHolds(): bool
    {
        return $this->registry->find('Acme')?->enabled === true;
    }

    /** Its precondition: the plugin is in the registry at all. */
    private function preconditionOfEnableHolds(): bool
    {
        return $this->registry->find('Acme') !== null;
    }

    /**
     * The inverse an operation DECLARED, found on the table by identity.
     *
     * @param array<string, Operation> $table
     */
    private function resolve(Operation $operation, array $table): ?Operation
    {
        $named = $operation->effects?->rollbackOperation();
        if ($named === null) {
            return null;
        }

        foreach ($table as $candidate) {
            if ($named->is($candidate->name)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The table, with the collaborator the INVERSE needs wired.
     *
     * `plugins.disable` asks an {@see ActivationSafetyInterface} whether turning this plugin off would
     * leave the capability graph without a provider, and REFUSES when no host wired one. See
     * {@see self::testAHostWithoutTheSafetyEvaluatorCannotKeepThePromise()}: that refusal is the finding
     * this falsifier produced on its first run, and it is why the promise is measured on a host that can
     * keep it — and named on one that cannot.
     *
     * @return array<string, Operation>
     */
    private function table(bool $withSafety = true): array
    {
        $byName = [];
        $operations = new PluginOperations($this->registry, null, [], $withSafety ? self::safety() : null);
        foreach ($operations->operations() as $operation) {
            $byName[$operation->name] = $operation;
        }

        return $byName;
    }

    /** A host that CAN answer «would turning this off break the graph?» — and answers no. */
    private static function safety(): ActivationSafetyInterface
    {
        return new class () implements ActivationSafetyInterface {
            public function blockingReasonWithout(string $pluginName): ?string
            {
                return null;
            }

            public function blockingReasonWith(string $newPluginClass): ?string
            {
                return null;
            }
        };
    }
}
