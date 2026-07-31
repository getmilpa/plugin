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

use Milpa\Plugin\ContractResolver;
use Milpa\Plugin\Runtime\MetadataGraphResolver;
use PHPUnit\Framework\TestCase;

/**
 * Orden slice, Ola 6c T5: {@see MetadataGraphResolver} is the pure-array gate+order CLI consumers
 * (`coa:plugins deps`, `coa:plugins simulate`) reach for instead of the deprecated
 * {@see ContractResolver} — no plugin directory, no reflection off a real class, just the same
 * one-resolution semantics {@see \Milpa\Plugin\Runtime\PluginsManager} boots with, run against
 * metadata records handed in directly.
 *
 * The fixtures mirror {@see \Milpa\Plugin\Tests\Runtime\PluginsManagerFreshPathTest}'s dependency
 * chain — Alpha requires what Beta provides, Beta requires what Gamma provides, scanned in
 * alphabetical (inverted-boot-order) sequence — but as plain arrays: this class never touches disk
 * or a real plugin class, so the fixture never needs to be either.
 */
final class MetadataGraphResolverTest extends TestCase
{
    private MetadataGraphResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new MetadataGraphResolver();
    }

    public function testOrderResequencesTheDependencyChainToProvidersFirst(): void
    {
        $ordered = $this->resolver->order($this->dependencyFixture());

        $this->assertSame(
            ['GammaFixture', 'BetaFixture', 'AlphaFixture'],
            array_column($ordered, 'name'),
            'GammaFixture provides what BetaFixture requires, which provides what AlphaFixture requires — providers must boot first.'
        );
    }

    public function testOrderThrowsWithALearnableLineWhenARequireHasNoProvider(): void
    {
        $orphan = [
            $this->record('OrphanFixture', requires: ['Milpa\\Fixtures\\GhostInterface']),
        ];

        try {
            $this->resolver->order($orphan);
            $this->fail('A hard requires no plugin provides must block the graph.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('MILPA_CAPABILITY_MISSING', $e->getMessage());
        }
    }

    public function testOrderReturnsAnEmptyListForAnEmptyInput(): void
    {
        $this->assertSame([], $this->resolver->order([]));
    }

    public function testOrderMatchesTheDeprecatedContractResolverForTheSameInput(): void
    {
        $fixture = $this->dependencyFixture();

        $freshOrder = array_column($this->resolver->order($fixture), 'name');
        $legacyOrder = array_column((new ContractResolver())->getLoadOrder($fixture), 'name');

        $this->assertSame(
            $legacyOrder,
            $freshOrder,
            'MetadataGraphResolver must reach the exact same load order the deprecated ContractResolver produced — the equivalence that justifies retiring its call sites.'
        );
    }

    /**
     * Three metadata records whose SCAN (alphabetical) order inverts the boot order: Alpha
     * requires what Beta provides, Beta requires what Gamma provides.
     *
     * @return list<array<string, mixed>>
     */
    private function dependencyFixture(): array
    {
        return [
            $this->record('AlphaFixture', requires: ['Milpa\\Fixtures\\BetaContract']),
            $this->record('BetaFixture', provides: ['Milpa\\Fixtures\\BetaContract'], requires: ['Milpa\\Fixtures\\GammaContract']),
            $this->record('GammaFixture', provides: ['Milpa\\Fixtures\\GammaContract']),
        ];
    }

    /**
     * @param list<string> $provides
     * @param list<string> $requires
     *
     * @return array<string, mixed>
     */
    private function record(string $name, array $provides = [], array $requires = []): array
    {
        return [
            'name' => $name,
            'version' => '1.0.0',
            'author' => 'Acme',
            'site' => 'https://teamx.agency',
            'type' => 'Service',
            'provides' => $provides,
            'requires' => $requires,
            'suggests' => [],
        ];
    }
}
