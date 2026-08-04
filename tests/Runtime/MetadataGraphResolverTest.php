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

    /**
     * SIN EL CARGADOR, EL GRAFO SE RESUELVE IGUAL — sólo con menos.
     *
     * Este paquete declara `milpa/resolver: ^0.5.2 || ^0.6` e `InstalledCapabilityLoader` sólo existe
     * en 0.6. Llamarla sin comprobar afirmaba una versión que el pin no exige, y con 0.5 instalado
     * reventaba con `Class not found` a media resolución — el arranque entero.
     *
     * Lo cazó la ceremonia de release contra el paquete de Packagist; aquí la clase siempre está, así
     * que la rama se ejerce inyectando un nombre que no existe. Una guarda que no se puede probar es
     * una que nadie sabe si funciona.
     */
    public function testItResolvesWithoutTheCapabilityLoader(): void
    {
        $reporte = (new MetadataGraphResolver())->diagnose(
            [],
            sys_get_temp_dir(),
            'Milpa\\Resolver\\Ingest\\NoExisteEsteCargador',
        );

        self::assertSame([], $reporte->errors, 'sin provisiones el grafo cierra por vacío, no revienta');
    }

    /** Sin `milpa.json` se diagnostica sin perfil: es lo que hacía siempre, no un error. */
    public function testWithoutAHostManifestItResolvesWithoutAProfile(): void
    {
        $raiz = sys_get_temp_dir() . '/milpa-sin-manifiesto-' . bin2hex(random_bytes(4));
        mkdir($raiz, 0o775, true);

        $reporte = (new MetadataGraphResolver())->diagnose([], $raiz, 'NoExiste\\Cargador');

        self::assertSame([], $reporte->errors);
        @rmdir($raiz);
    }

    /**
     * UN PERFIL MALFORMADO NO SE INVENTA NI SE IGNORA EN SILENCIO: se diagnostica sin él, que es lo
     * que hacía antes de que el perfil se leyera. Inventar uno sería peor que no tenerlo.
     */
    public function testAMalformedHostProfileIsDiagnosedWithoutIt(): void
    {
        $raiz = sys_get_temp_dir() . '/milpa-perfil-roto-' . bin2hex(random_bytes(4));
        mkdir($raiz, 0o775, true);
        file_put_contents($raiz . '/milpa.json', '{"hostProfile": {"name": ""}}');

        $reporte = (new MetadataGraphResolver())->diagnose([], $raiz, 'NoExiste\\Cargador');

        self::assertSame([], $reporte->errors);
        @unlink($raiz . '/milpa.json');
        @rmdir($raiz);
    }

    /** Y un `milpa.json` que ni siquiera es JSON tampoco tumba el diagnóstico. */
    public function testAManifestThatIsNotJsonDoesNotBreakTheDiagnosis(): void
    {
        $raiz = sys_get_temp_dir() . '/milpa-json-roto-' . bin2hex(random_bytes(4));
        mkdir($raiz, 0o775, true);
        file_put_contents($raiz . '/milpa.json', 'esto no es json');

        self::assertSame([], (new MetadataGraphResolver())->diagnose([], $raiz, 'NoExiste\\Cargador')->errors);
        @unlink($raiz . '/milpa.json');
        @rmdir($raiz);
    }

    /** Con perfil, una app SIN plugins que declara necesitar algo tiene el grafo abierto. */
    public function testAnAppWithNoPluginsButARequirementHasAnOpenGraph(): void
    {
        $raiz = sys_get_temp_dir() . '/milpa-perfil-' . bin2hex(random_bytes(4));
        mkdir($raiz, 0o775, true);
        file_put_contents($raiz . '/milpa.json', json_encode([
            'hostProfile' => ['name' => 'app', 'version' => '1.0', 'requiredCapabilities' => ['no.la.provee.nadie']],
        ], JSON_THROW_ON_ERROR));

        $reporte = (new MetadataGraphResolver())->diagnose([], $raiz, 'NoExiste\\Cargador');

        self::assertNotSame([], $reporte->errors, 'el atajo de «sin plugins cierra por vacío» no aplica con perfil');
        @unlink($raiz . '/milpa.json');
        @rmdir($raiz);
    }
}
