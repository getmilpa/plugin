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
use Milpa\Resolver\Report\ResolutionStatus;
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

    /**
     * `diagnose()` CONTESTA donde `order()` lanza — y ésa es toda su razón de ser.
     *
     * Con el grafo roto la app no bootea, así que `coa` no despacha, así que ninguna herramienta de
     * diagnóstico corre: medido en una app de ejemplo, las quince herramientas del agente caídas y una
     * línea de error como único dato. La diagnosis moría con el paciente. Este método es el mismo
     * cálculo, disponible sin bootear.
     */
    public function testDiagnoseAnswersWhereOrderThrows(): void
    {
        $registros = [[
            'name' => 'Roto',
            'version' => '0.1.0',
            'type' => 'Service',
            'provides' => [],
            'requires' => ['search'],
            'suggests' => [],
        ]];

        // `order()` lanza, como debe: sin grafo no hay orden de carga que producir.
        try {
            (new MetadataGraphResolver())->order($registros);
            self::fail('un grafo que no cierra no puede producir un orden');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('search', $e->getMessage());
        }

        // `diagnose()` devuelve el reporte ENTERO, que es lo que el arranque tiraba.
        $reporte = (new MetadataGraphResolver())->diagnose($registros);

        self::assertSame(ResolutionStatus::Blocked, $reporte->status);
        self::assertNotSame([], $reporte->errors, 'los errores aprendibles sobreviven');

        $primero = $reporte->errors[0]->toArray();
        self::assertSame('MILPA_CAPABILITY_MISSING', $primero['code']);
        self::assertNotSame('', $primero['why'], 'el POR QUÉ, que la excepción conservaba a medias');
        self::assertNotSame([], $primero['fixes'], 'y cómo se arregla');
        self::assertArrayHasKey('recommendedActions', $primero, 'lo que un agente puede aplicar sin interpretar');
    }

    /** Un grafo que cierra se reporta como tal, con su orden de carga. */
    public function testDiagnoseOnAGraphThatClosesReportsItAsValid(): void
    {
        $reporte = (new MetadataGraphResolver())->diagnose([
            [
                'name' => 'Buscador',
                'version' => '1.0.0',
                'type' => 'Service',
                'provides' => ['search'],
                'requires' => [],
                'suggests' => [],
            ],
            [
                'name' => 'Blog',
                'version' => '1.0.0',
                'type' => 'Web',
                'provides' => [],
                'requires' => ['search'],
                'suggests' => [],
            ],
        ]);

        self::assertNotSame(ResolutionStatus::Blocked, $reporte->status);
        self::assertSame([], $reporte->errors);
    }

    /**
     * Una app SIN plugins tiene un grafo que cierra por vacío, no uno roto.
     *
     * Contestar `Blocked` mandaría a alguien a buscar un proveedor faltante en una lista vacía — y ese
     * es el estado de toda app recién creada antes de instalar nada.
     */
    public function testAnAppWithNoPluginsIsValidAndNotBlocked(): void
    {
        $reporte = (new MetadataGraphResolver())->diagnose([]);

        self::assertSame(ResolutionStatus::Valid, $reporte->status);
        self::assertSame([], $reporte->errors);
    }
}
