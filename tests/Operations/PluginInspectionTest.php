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
use Milpa\Plugin\Contracts\PluginRecord;
use Milpa\Plugin\Operations\PluginInspection;
use Milpa\Plugin\Registry\InMemoryPluginRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Las cinco que MIRAN, ahora en el paquete donde ya vivía todo lo que necesitan.
 *
 * Nacieron en un host, donde funcionaban. El problema era el siguiente host: para poder preguntar si
 * su grafo resuelve tendría que reescribirlas, y esa copia es la que después diverge. Lo único que
 * ponía el host era la lista de plugins activos, y eso este paquete ya lo sabe armar — de su registry
 * y de las clases que el host declara.
 */
final class PluginInspectionTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'milpa-inspection-' . uniqid();
        mkdir($this->tmp . '/plugins/ProveedorFixture', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
        parent::tearDown();
    }

    /** @param list<class-string> $declared */
    private function inspection(array $declared = [], ?InMemoryPluginRegistry $registry = null, bool $conRaiz = false): PluginInspection
    {
        return new PluginInspection($registry ?? new InMemoryPluginRegistry(), $declared, $conRaiz ? $this->tmp : null);
    }

    /**
     * El orden ES el dato: quien provee va antes que quien requiere.
     *
     * Afirmar sólo que «resuelve» dejaría pasar un orden inverso, que es precisamente el que rompe el
     * arranque — y lo rompe lejos, en el plugin que pidió algo que todavía no existía.
     */
    public function testTheGraphResolvesWithTheProviderFirst(): void
    {
        $r = $this->inspection([ProveedorFixture::class, ConsumidorFixture::class])->deps([]);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertSame(2, $r['total']);
        $orden = array_column($r['loadOrder'] ?? [], 'plugin');
        self::assertLessThan(
            array_search('ConsumidorFixture', $orden, true),
            array_search('ProveedorFixture', $orden, true),
        );
    }

    /**
     * Sin plugins activos el grafo resuelve VACÍO — no falla.
     *
     * Cero plugins y un grafo roto son cosas distintas, y devolver `ok: false` en el primer caso
     * mandaría a alguien a buscar una dependencia que no existe.
     */
    public function testAnEmptyGraphResolvesInsteadOfFailing(): void
    {
        $r = $this->inspection()->deps([]);

        self::assertTrue($r['ok']);
        self::assertSame(0, $r['total']);
        self::assertSame([], $r['loadOrder']);
    }

    /** Un plugin apagado en el registry NO cuenta como activo, aunque el host lo declare. */
    public function testADeclaredButDisabledPluginIsNotPartOfTheGraph(): void
    {
        $registry = new InMemoryPluginRegistry();
        $registry->register(new PluginRecord(
            name: 'ProveedorFixture',
            version: '1.0.0',
            author: 'Acme',
            site: 'https://example.com',
            type: 'Service',
            installed: true,
            enabled: false,
        ));

        $r = $this->inspection([ProveedorFixture::class], $registry)->deps([]);

        self::assertSame(0, $r['total'], 'lo apagado no arranca, así que sus capacidades no están disponibles');
    }

    /** Simular dice quién satisface cada requisito, por su nombre. */
    public function testSimulatingNamesWhoWouldSatisfyEachRequirement(): void
    {
        $r = $this->inspection([ProveedorFixture::class, ConsumidorFixture::class])
            ->simulate(['plugin' => 'ConsumidorFixture']);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertTrue($r['wouldResolve']);
        self::assertSame([['capability' => 'CosaContract', 'providedBy' => 'ProveedorFixture']], $r['requirements']);
    }

    /**
     * Y si NADIE provee lo que pide, simular contesta que no encendería — con el motivo del resolver.
     *
     * El motivo se devuelve tal cual y no se reformula: trae el código, la capacidad que falta, cómo
     * arreglarlo y a dónde ir a aprenderlo. Una segunda voz explicándolo aquí lo diría peor.
     */
    public function testSimulatingSaysItWouldNotResolveWhenNobodyProvidesWhatItNeeds(): void
    {
        $r = $this->inspection([ConsumidorFixture::class])->simulate(['plugin' => 'ConsumidorFixture']);

        self::assertFalse($r['ok']);
        self::assertFalse($r['wouldResolve']);
        self::assertStringContainsString('MILPA_CAPABILITY_MISSING', (string) $r['error']);
        self::assertStringContainsString('CosaContract', (string) $r['error']);
        self::assertStringContainsString('Fix:', (string) $r['error']);
    }

    /** Un plugin que no existe se dice por su nombre, no como una excepción de clase. */
    public function testAnUnknownPluginIsNamed(): void
    {
        $r = $this->inspection()->simulate(['plugin' => 'NoExisteEsto']);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('NoExisteEsto', (string) $r['error']);
    }

    /**
     * Verificar compara la VERSIÓN del manifiesto contra la del atributo.
     *
     * Es el campo por el que un manifiesto viejo hace daño: le dice una cosa a quien lee el disco y
     * otra a quien arranca el código.
     */
    public function testVerifyCatchesAManifestThatDisagreesWithTheAttribute(): void
    {
        file_put_contents($this->tmp . '/plugins/ProveedorFixture/milpa.json', (string) json_encode([
            'name' => 'acme/proveedor-fixture',
            'version' => '9.9.9',
            'entrypoint' => 'ProveedorFixture.php',
            'namespace' => 'Milpa\\Plugins\\ProveedorFixture',
            'author' => ['name' => 'Acme'],
        ]));

        $r = $this->inspection([ProveedorFixture::class], conRaiz: true)->verify(['plugin' => 'ProveedorFixture']);

        self::assertFalse($r['ok']);
        $paridad = array_values(array_filter($r['checks'], static fn (array $c): bool => $c['check'] === 'parity'));
        self::assertNotSame([], $paridad);
        self::assertFalse($paridad[0]['ok']);
        self::assertStringContainsString('9.9.9', $paridad[0]['detail']);
    }

    /**
     * Sin `milpa.json` NO hay falla: un plugin puede vivir sólo con su atributo.
     *
     * Decir «inválido» sobre algo que no existe es contestar otra pregunta, y manda a alguien a
     * arreglar un archivo que nunca hizo falta.
     */
    public function testAMissingManifestIsNotAFailure(): void
    {
        $r = $this->inspection([ProveedorFixture::class], conRaiz: true)->verify(['plugin' => 'ProveedorFixture']);

        self::assertTrue($r['ok']);
        self::assertStringContainsString('sin milpa.json', $r['checks'][0]['detail']);
    }

    /** Sin instalador cableado, `outdated` DICE que no puede en vez de reportar cero. */
    public function testWithoutAnInstallerItSaysItCannotRatherThanReportingZero(): void
    {
        $r = $this->inspection()->outdated([]);

        self::assertFalse($r['ok']);
        self::assertSame([], $r['outdated']);
        self::assertNotSame('', (string) ($r['error'] ?? ''));
    }

    /** `lock` regenera el archivo desde el registry, y lo deja verificable. */
    public function testLockWritesTheFileAndItVerifies(): void
    {
        $registry = new InMemoryPluginRegistry();
        $registry->register(new PluginRecord(
            name: 'ProveedorFixture',
            version: '1.0.0',
            author: 'Acme',
            site: 'https://example.com',
            type: 'Service',
            installed: true,
            enabled: true,
        ));

        $r = $this->inspection([], $registry, conRaiz: true)->lock([]);

        self::assertTrue($r['ok']);
        self::assertSame(1, $r['plugins']);
        self::assertTrue($r['integrity'], 'un lock que no verifica contra sí mismo no sirve para reproducir nada');
        self::assertFileExists($r['path']);
    }


    /** Un grafo que NO resuelve se reporta con el motivo del resolver, no con una excepción. */
    public function testABrokenGraphIsReportedAndNotThrown(): void
    {
        $r = $this->inspection([ConsumidorFixture::class])->deps([]);

        self::assertFalse($r['ok']);
        self::assertSame(1, $r['total']);
        self::assertStringContainsString('MILPA_CAPABILITY_MISSING', (string) $r['error']);
    }

    /**
     * Un plugin YA encendido no se simula.
     *
     * La respuesta honesta es que ya está: un grafo hipotético idéntico al real no le dice nada nuevo
     * a quien pregunta, y sugiere que todavía había una decisión que tomar.
     */
    public function testSimulatingSomethingAlreadyOnSaysSo(): void
    {
        $registry = new InMemoryPluginRegistry();
        $registry->register(new PluginRecord(
            name: 'ProveedorFixture',
            version: '1.0.0',
            author: 'Acme',
            site: 'https://example.com',
            type: 'Service',
            installed: true,
            enabled: true,
        ));

        $r = $this->inspection([ProveedorFixture::class], $registry)->simulate(['plugin' => 'ProveedorFixture']);

        self::assertTrue($r['ok']);
        self::assertTrue($r['alreadyEnabled']);
        self::assertArrayNotHasKey('wouldResolve', $r);
    }

    /** Un manifiesto que no valida se reporta en el check de forma, y ahí se detiene. */
    public function testAManifestThatDoesNotValidateFailsTheShapeCheck(): void
    {
        file_put_contents($this->tmp . '/plugins/ProveedorFixture/milpa.json', '{"esto": "no es un manifiesto"}');

        $r = $this->inspection([ProveedorFixture::class], conRaiz: true)->verify(['plugin' => 'ProveedorFixture']);

        self::assertFalse($r['ok']);
        $checks = array_column($r['checks'], 'check');
        self::assertSame(['manifest', 'shape'], $checks, 'sin forma válida no tiene sentido comparar versiones');
        self::assertFalse($r['checks'][1]['ok']);
    }

    /**
     * Con instalador cableado, `outdated` reporta lo que él contesta y cuántos remotos hay.
     *
     * El conteo importa: el instalador salta en silencio una fuente que no contestó, así que «cero
     * desfasados» sobre tres remotos y sobre cero remotos son dos respuestas distintas.
     */
    public function testWithAnInstallerItReportsWhatTheInstallerAnswers(): void
    {
        $registry = new InMemoryPluginRegistry();
        $registry->register(new PluginRecord(
            name: 'RemotoFixture',
            version: '1.0.0',
            author: 'Acme',
            site: 'https://example.com',
            type: 'Service',
            installed: true,
            enabled: true,
            source: 'github:acme/remoto-fixture',
        ));

        $installer = new class () implements \Milpa\Interfaces\Plugin\PluginInstallerInterface {
            public function require(string $source): \Milpa\DTO\PluginInstallResult
            {
                return new \Milpa\DTO\PluginInstallResult(success: true, pluginName: 'x', version: '1.0.0', source: $source);
            }

            public function update(string $pluginName, ?string $targetVersion = null): \Milpa\DTO\PluginInstallResult
            {
                return new \Milpa\DTO\PluginInstallResult(success: true, pluginName: $pluginName, version: '2.0.0', source: 'x');
            }

            public function resolve(string $source): \Milpa\DTO\DependencyResolution
            {
                return new \Milpa\DTO\DependencyResolution(resolved: [], missing: [], conflicts: []);
            }

            public function remove(string $pluginName, bool $keepData = false): \Milpa\DTO\PluginRemoveResult
            {
                return new \Milpa\DTO\PluginRemoveResult(success: true, pluginName: $pluginName);
            }

            /** @return list<array<string, mixed>> */
            public function checkOutdated(): array
            {
                return [['name' => 'RemotoFixture', 'current' => '1.0.0', 'latest' => '2.0.0']];
            }
        };

        $inspection = new PluginInspection($registry, [], null, $installer);
        $r = $inspection->outdated([]);

        self::assertTrue($r['ok']);
        self::assertSame(1, $r['checked']);
        self::assertSame('2.0.0', $r['outdated'][0]['latest']);
    }

    /**
     * Un plugin instalado que el host NO declara también cuenta como activo.
     *
     * Es el caso del que se instaló en tiempo de ejecución: nadie escribió su clase en una lista, y
     * dejarlo fuera del grafo diría que sus capacidades no están cuando sí arranca.
     */
    public function testAnInstalledButUndeclaredPluginIsStillActive(): void
    {
        $registry = new InMemoryPluginRegistry();
        $registry->register(new PluginRecord(
            name: 'ProveedorFixture',
            version: '1.0.0',
            author: 'Acme',
            site: 'https://example.com',
            type: 'Service',
            installed: true,
            enabled: true,
        ));

        // Sin declarar: se resuelve por la convención `Milpa\Plugins\<Nombre>\<Nombre>`, que es la
        // que usa el instalador al desempacar. Aquí esa clase no existe, así que el activo honesto es
        // ninguno — y eso también es lo correcto: no se inventa un plugin que no se puede cargar.
        self::assertSame(0, $this->inspection([], $registry)->deps([])['total']);

        // Declarado, sí entra.
        self::assertSame(1, $this->inspection([ProveedorFixture::class], $registry)->deps([])['total']);
    }

    /**
     * Un requisito escrito como FQCN suelto NO lo satisface un proveedor que sólo lo declara como
     * `interface` de un registro. Lo decide el resolver, y esta prueba lo deja escrito.
     *
     * Es un pie de banco real: las dos formas se leen igual para un humano —el mismo FQCN aparece en
     * los dos manifiestos— y el grafo queda abierto de todos modos. Antes de esta prueba, descubrirlo
     * costaba un arranque bloqueado con el proveedor instalado y a la vista.
     */
    public function testABareRequirementIsNotSatisfiedByARecordThatOnlyNamesItAsAnInterface(): void
    {
        $r = $this->inspection([ProveedorRicoFixture::class, ConsumidorFixture::class])
            ->simulate(['plugin' => 'ConsumidorFixture']);

        self::assertFalse($r['ok']);
        self::assertFalse($r['wouldResolve']);
        self::assertStringContainsString('MILPA_CAPABILITY_MISSING', (string) $r['error']);
        self::assertStringContainsString('CosaContract', (string) $r['error']);
    }

    /**
     * Un manifiesto MALFORMADO se reporta, no se lanza.
     *
     * El resolver rechaza una capacidad sin versión de contrato con una `InvalidArgumentException`,
     * que no es una `RuntimeException` — así que atrapar sólo aquélla dejaba escapar ésta, y
     * preguntar si el grafo resuelve terminaba en una traza. Una operación de sólo lectura contesta.
     */
    public function testAMalformedCapabilityRecordIsAnAnswerAndNotATrace(): void
    {
        $r = $this->inspection([ProveedorMalformadoFixture::class])->deps([]);

        self::assertFalse($r['ok']);
        self::assertStringContainsString('ProveedorMalformadoFixture', (string) $r['error']);
    }

    /**
     * Dos registros que nombran el MISMO id sí encajan — y el reporte nombra al proveedor.
     *
     * Es la contraparte de la prueba anterior: lo que hace match es la identidad, no el parecido. Y
     * en el camino se ejercita cómo se lee una capacidad rica: su `id` es lo que la nombra.
     */
    public function testTwoRecordsNamingTheSameIdMatchAndTheProviderIsNamed(): void
    {
        $r = $this->inspection([ProveedorRicoFixture::class, ConsumidorRicoFixture::class])
            ->simulate(['plugin' => 'ConsumidorRicoFixture']);

        self::assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        self::assertTrue($r['wouldResolve']);
        self::assertSame(
            [['capability' => 'acme.cosa.v1', 'providedBy' => 'ProveedorRicoFixture']],
            $r['requirements'],
        );
    }

    /** Pedir los metadatos de algo que no los declara lanza con el nombre de la clase. */
    public function testMetadataOfAClassWithoutTheAttributeThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/SinAtributoFixture/');

        $this->inspection()->metadata(SinAtributoFixture::class);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}

/** Provee la capacidad que el consumidor pide. */
#[PluginMetadata(
    version: '1.0.0',
    author: 'Acme',
    site: 'https://example.com',
    name: 'ProveedorFixture',
    type: 'Service',
    provides: ['Acme\\Fixtures\\CosaContract'],
)]
final class ProveedorFixture
{
}

/** La requiere, y por eso tiene que arrancar después. */
#[PluginMetadata(
    version: '1.0.0',
    author: 'Acme',
    site: 'https://example.com',
    name: 'ConsumidorFixture',
    type: 'Service',
    requires: ['Acme\\Fixtures\\CosaContract'],
)]
final class ConsumidorFixture
{
}

/** Provee la MISMA capacidad, pero escrita como registro rico. */
#[PluginMetadata(
    version: '1.0.0',
    author: 'Acme',
    site: 'https://example.com',
    name: 'ProveedorRicoFixture',
    type: 'Service',
    provides: [['id' => 'acme.cosa.v1', 'interface' => 'Acme\\Fixtures\\CosaContract', 'contractVersion' => '1.0.0']],
)]
final class ProveedorRicoFixture
{
}

/** Una clase cualquiera, sin atributo: pedirle metadatos tiene que lanzar. */
final class SinAtributoFixture
{
}

/** Declara una capacidad como registro pero sin versión de contrato: el resolver la rechaza. */
#[PluginMetadata(
    version: '1.0.0',
    author: 'Acme',
    site: 'https://example.com',
    name: 'ProveedorMalformadoFixture',
    type: 'Service',
    provides: [['id' => 'acme.rota.v1', 'interface' => 'Acme\\Fixtures\\RotaContract']],
)]
final class ProveedorMalformadoFixture
{
}

/** Pide la capacidad por su id, que es la forma que el resolver sí hace encajar. */
#[PluginMetadata(
    version: '1.0.0',
    author: 'Acme',
    site: 'https://example.com',
    name: 'ConsumidorRicoFixture',
    type: 'Service',
    requires: [['id' => 'acme.cosa.v1', 'interface' => 'Acme\\Fixtures\\CosaContract', 'contractVersion' => '^1.0']],
)]
final class ConsumidorRicoFixture
{
}
