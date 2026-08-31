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
use Milpa\Plugin\Contracts\ActivationSafetyInterface;
use Milpa\Plugin\Contracts\StateBaselineInterface;
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
     * Un requisito escrito como FQCN suelto SÍ lo satisface un proveedor que lo declara como
     * `interface` de un registro rico. Antes no, y ése era el pie de banco.
     *
     * Las dos formas se leen igual para un humano —el mismo FQCN aparece en los dos manifiestos— y
     * el grafo quedaba abierto de todos modos, porque el motor comparaba SÓLO `id` mientras el
     * chequeo pre-boot, el validador y el inspector contaban también `interface`. Tres contra uno, y
     * el que disentía era el que bloquea el arranque. Con {@see \Milpa\Services\CapabilityMatcher}
     * un registro ofrece sus dos identidades en las cuatro superficies.
     *
     * P17.3 · `settlement-q-p17.md`, que además midió que la correspondencia id↔interfaz ya es
     * biyectiva en esta familia, así que unificarlas no colapsa dos capacidades en una.
     */
    public function testABareRequirementIsSatisfiedByARecordThatNamesItAsAnInterface(): void
    {
        $r = $this->inspection([ProveedorRicoFixture::class, ConsumidorFixture::class])
            ->simulate(['plugin' => 'ConsumidorFixture']);

        self::assertTrue($r['wouldResolve'], (string) ($r['error'] ?? ''));
        self::assertTrue($r['ok']);
    }

    /**
     * El control negativo del anterior: unificar identidades no volvió al motor incapaz de reportar
     * una capacidad que de verdad no provee nadie (ADR-0029).
     */
    public function testACapabilityNobodyProvidesIsStillMissing(): void
    {
        $r = $this->inspection([ConsumidorFixture::class])->simulate(['plugin' => 'ConsumidorFixture']);

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

    /**
     * EL ÍNDICE INVERSO: quién provee cada capacidad y quién la usa (P17.2).
     *
     * Es la mitad que faltaba. Un agente que sólo ve «cada plugin declara esto» tiene que reconstruir
     * «quién depende de qué» en su cabeza, en cada vuelta y sin poder verificarlo. Cruzarlo aquí
     * cuesta un bucle y se calcula una vez.
     */
    public function testTheArchitectureCrossesTheGraphBothWays(): void
    {
        $r = $this->inspection([ProveedorFixture::class, ConsumidorFixture::class])->architecture([]);

        self::assertTrue($r['ok']);
        self::assertSame(2, $r['total']);
        self::assertSame([], $r['unsatisfied']);

        self::assertCount(1, $r['capabilities']);
        $capacidad = $r['capabilities'][0];
        self::assertSame('Acme\\Fixtures\\CosaContract', $capacidad['id']);
        self::assertSame(['ProveedorFixture'], $capacidad['providedBy']);
        self::assertSame(['ConsumidorFixture'], $capacidad['requiredBy'], 'quién la usa — lo que antes había que cruzar a mano');
        self::assertTrue($capacidad['satisfied']);
    }

    /**
     * Una capacidad que alguien pide y nadie da se reporta como huérfana, y ABRE el veredicto.
     *
     * Es la misma condición que impide arrancar, contestada por una operación de sólo lectura en vez
     * de por una excepción de arranque.
     */
    public function testACapabilityNobodyProvidesIsReportedAsUnsatisfied(): void
    {
        $r = $this->inspection([ConsumidorFixture::class])->architecture([]);

        self::assertFalse($r['ok']);
        self::assertSame(['Acme\\Fixtures\\CosaContract'], $r['unsatisfied']);

        $capacidad = $r['capabilities'][0];
        self::assertSame([], $capacidad['providedBy']);
        self::assertFalse($capacidad['satisfied']);
    }

    /**
     * QUÉ SE ROMPE si apagas cada plugin — preguntado, no intentado.
     *
     * `blockingReasonWithout()` existía y sólo se alcanzaba al NEGAR un `plugins.disable`: la única
     * forma de saber qué se rompía era intentar romperlo. Preguntar antes de causar es la misma
     * distinción que separa `simulate` de `enable`.
     */
    public function testItSaysWhatWouldBreakWithoutTryingToBreakIt(): void
    {
        $safety = new class () implements ActivationSafetyInterface {
            public function blockingReasonWithout(string $pluginName): ?string
            {
                return $pluginName === 'ProveedorFixture'
                    ? 'ConsumidorFixture requires "Acme\\Fixtures\\CosaContract" and nobody else provides it.'
                    : null;
            }

            public function blockingReasonWith(string $newPluginClass): ?string
            {
                return null;
            }
        };

        $inspection = new PluginInspection(
            new InMemoryPluginRegistry(),
            [ProveedorFixture::class, ConsumidorFixture::class],
            null,
            null,
            $safety,
        );

        $porNombre = [];
        foreach ($inspection->architecture([])['plugins'] as $plugin) {
            $porNombre[$plugin['name']] = $plugin['breaksIfDisabled'];
        }

        self::assertStringContainsString('ConsumidorFixture requires', (string) $porNombre['ProveedorFixture']);
        self::assertNull($porNombre['ConsumidorFixture'], 'apagar al que sólo consume no rompe nada');
    }

    /**
     * SIN el contrato de seguridad, el impacto se DERIVA del índice.
     *
     * Ese contrato necesita un perfil de host, y un host que no lo declara —la app que sale de un
     * `create-project`, por ejemplo— recibía `null` en todos los plugins: «no se pudo preguntar»
     * presentado como si fuera «nada se rompe», que es la peor de las respuestas porque autoriza un
     * `disable` con una tranquilidad que nadie verificó. La derivación no necesita perfil: si es el
     * único proveedor de algo que alguien pide, apagarlo deja a ese alguien sin proveedor.
     */
    public function testWithoutTheSafetyContractTheImpactIsDerivedFromTheIndex(): void
    {
        $r = $this->inspection([ProveedorFixture::class, ConsumidorFixture::class])->architecture([]);

        $porNombre = [];
        foreach ($r['plugins'] as $plugin) {
            $porNombre[$plugin['name']] = $plugin['breaksIfDisabled'];
        }

        self::assertStringContainsString('único que provee', (string) $porNombre['ProveedorFixture']);
        self::assertStringContainsString('ConsumidorFixture', (string) $porNombre['ProveedorFixture']);
        self::assertNull($porNombre['ConsumidorFixture'], 'apagar al que sólo consume no rompe nada');
    }

    /**
     * Y con DOS proveedores, apagar uno no rompe nada: el otro sigue.
     *
     * Es lo que distingue una derivación de una heurística asustadiza. Sin este caso, «es proveedor»
     * y «es el único proveedor» se confundirían, y la respuesta bloquearía apagados perfectamente
     * seguros.
     */
    public function testWithTwoProvidersTurningOneOffBreaksNothing(): void
    {
        $r = $this->inspection([
            ProveedorFixture::class,
            ProveedorRicoFixture::class,
            ConsumidorFixture::class,
        ])->architecture([]);

        $porNombre = [];
        foreach ($r['plugins'] as $plugin) {
            $porNombre[$plugin['name']] = $plugin['breaksIfDisabled'];
        }

        self::assertNull($porNombre['ProveedorFixture'], 'el otro proveedor sigue ahí');
        self::assertNull($porNombre['ProveedorRicoFixture']);
    }

    /**
     * SIN alguien que diga desde cuándo miras, el reporte lo DICE en vez de suponer que nada cambió.
     *
     * `null` y «no cambió nada» son afirmaciones distintas, y confundirlas es el error entero: un
     * reporte que omitiera el campo se lee como «esto es el mundo», y el mundo del que habla puede
     * llevar encima los apagados que hizo el propio lector.
     */
    public function testWithoutABaselineProviderTheReportSaysSoInsteadOfClaimingNothingChanged(): void
    {
        $r = $this->inspection([ProveedorFixture::class, ConsumidorFixture::class])->architecture([]);

        self::assertArrayHasKey('baseline', $r);
        self::assertNull($r['baseline'], 'no se pudo preguntar, y eso no es «no cambió nada»');
    }

    /**
     * Con línea base y nada movido, el reporte lo dice en UNA palabra, no en un bloque.
     *
     * Medido en Q-P17-K: con el bloque completo en
     * todas las lecturas, la corrección de las corridas que no habían mutado cayó de 6 de 10 a 1 de
     * 10. El bloque era cierto y era inútil — no había nada que fechar.
     *
     * Y `true` NO es `null`: `null` dice que nadie llevó la cuenta, o sea que algo pudo cambiar sin
     * que se sepa. Ésa es la distinción que este campo existe para sostener.
     */
    public function testWhenNothingMovedSinceTheBaselineTheReportSaysSoInOneWord(): void
    {
        $inspection = new PluginInspection(
            new InMemoryPluginRegistry(),
            [ProveedorFixture::class, ConsumidorFixture::class],
            null,
            null,
            null,
            baseline: $this->baseline(['ProveedorFixture', 'ConsumidorFixture']),
        );

        $r = $inspection->architecture([]);

        self::assertTrue($r['baseline'], 'se preguntó y no cambió nada: una palabra, no un bloque');
        self::assertNotNull($r['baseline'], '`null` sería «nadie llevó la cuenta», que es otra cosa');

        $porNombre = [];
        foreach ($r['plugins'] as $plugin) {
            $porNombre[$plugin['name']] = $plugin['breaksIfDisabled'];
        }
        self::assertStringNotContainsString('ESTADO ACTUAL', (string) $porNombre['ProveedorFixture']);
    }

    /**
     * EL CASO MEDIDO, reproducido: dos proveedores, el lector apaga uno, y después lee el reporte.
     *
     * Es la corrida de Q-P17-J que se repitió 12
     * veces y se contestó mal 10. El agente leyó el `breaksIfDisabled` del proveedor SUPERVIVIENTE
     * —cierto: apagarlo a él sí rompería al consumidor— y lo citó como la consecuencia del apagado
     * que él acababa de hacer sobre el OTRO.
     *
     * Nadie le dijo nada falso. Lo que faltaba era desde qué estado se afirmaba, y eso es lo que esta
     * prueba fija: el aviso va PEGADO a la cadena que se citó mal, no sólo al pie del reporte.
     */
    public function testAfterTheReaderTurnedAPluginOffTheImpactFieldSaysItSpeaksFromTheCurrentState(): void
    {
        $registry = $this->conUnoApagado();

        $inspection = new PluginInspection(
            $registry,
            [ProveedorFixture::class, ProveedorRicoFixture::class, ConsumidorFixture::class],
            null,
            null,
            null,
            baseline: $this->baseline(['ProveedorFixture', 'ProveedorRicoFixture', 'ConsumidorFixture']),
        );

        $r = $inspection->architecture([]);

        self::assertFalse($r['baseline']['unchanged']);
        self::assertSame(['ProveedorRicoFixture'], $r['baseline']['disabledSince']);

        $porNombre = [];
        foreach ($r['plugins'] as $plugin) {
            $porNombre[$plugin['name']] = $plugin['breaksIfDisabled'];
        }

        // El superviviente SÍ rompe ahora al consumidor, y eso sigue siendo verdad. Lo que cambia es
        // que la cadena ya no se puede leer como una consecuencia de lo que el lector hizo.
        $aviso = (string) $porNombre['ProveedorFixture'];
        self::assertStringContainsString('único que provee', $aviso);
        self::assertStringContainsString('ESTADO ACTUAL', $aviso);
        self::assertStringContainsString('ProveedorRicoFixture', $aviso, 'nombra lo que el lector apagó');
    }

    /** Un veredicto que dice `null` no se decora: no hay nada que fechar. */
    public function testTheWarningIsNotAppendedToPluginsThatBreakNothing(): void
    {
        $registry = $this->conUnoApagado();

        $inspection = new PluginInspection(
            $registry,
            [ProveedorFixture::class, ProveedorRicoFixture::class, ConsumidorFixture::class],
            null,
            null,
            null,
            baseline: $this->baseline(['ProveedorFixture', 'ProveedorRicoFixture', 'ConsumidorFixture']),
        );

        $porNombre = [];
        foreach ($inspection->architecture([])['plugins'] as $plugin) {
            $porNombre[$plugin['name']] = $plugin['breaksIfDisabled'];
        }

        self::assertNull($porNombre['ConsumidorFixture']);
    }

    /**
     * Las TRES respuestas son distintas, y la prueba las fija juntas porque el defecto sería
     * confundirlas.
     *
     * `null` = nadie llevó la cuenta, así que algo pudo cambiar sin que se sepa · `true` = se llevó y
     * no cambió nada · `array` = se llevó y esto cambió. Colapsar las dos primeras ahorraría cuatro
     * tokens y borraría la única distinción que protege al lector.
     */
    public function testTheThreeBaselineAnswersAreDistinguishable(): void
    {
        $sin = $this->inspection([ProveedorFixture::class, ConsumidorFixture::class])->architecture([]);

        $quieto = (new PluginInspection(
            new InMemoryPluginRegistry(),
            [ProveedorFixture::class, ConsumidorFixture::class],
            null,
            null,
            null,
            baseline: $this->baseline(['ProveedorFixture', 'ConsumidorFixture']),
        ))->architecture([]);

        $movido = (new PluginInspection(
            $this->conUnoApagado(),
            [ProveedorFixture::class, ProveedorRicoFixture::class, ConsumidorFixture::class],
            null,
            null,
            null,
            baseline: $this->baseline(['ProveedorFixture', 'ProveedorRicoFixture', 'ConsumidorFixture']),
        ))->architecture([]);

        self::assertNull($sin['baseline'], 'nadie pudo llevar la cuenta');
        self::assertTrue($quieto['baseline'], 'se llevó y no cambió nada');
        self::assertIsArray($movido['baseline'], 'se llevó y esto cambió');
    }

    /** El registro tras un `plugins.disable` del lector: el rico queda apagado. */
    private function conUnoApagado(): InMemoryPluginRegistry
    {
        $registry = new InMemoryPluginRegistry();
        $registry->register(new PluginRecord(
            name: 'ProveedorRicoFixture',
            version: '1.0.0',
            author: 'fixture',
            site: 'https://example.com',
            type: 'Service',
            installed: true,
            enabled: false,
        ));

        return $registry;
    }

    /** @param list<string> $encendidos */
    private function baseline(array $encendidos): StateBaselineInterface
    {
        return new class ($encendidos) implements StateBaselineInterface {
            /** @param list<string> $encendidos */
            public function __construct(private readonly array $encendidos)
            {
            }

            /** @return list<string>|null */
            public function enabledAtBaseline(): ?array
            {
                return $this->encendidos;
            }

            public function baselineLabel(): string
            {
                return 'que empezó esta vuelta';
            }
        };
    }

    /**
     * CERO plugins activos también lleva línea base, y es el caso más peligroso de todos.
     *
     * Una app vacía y una app que el lector acaba de dejar vacía se ven idénticas en el reporte. La
     * segunda es alguien que apagó el último proveedor y va a leer «no hay nada» como si siempre
     * hubiera sido así.
     */
    public function testAnEmptyGraphStillSaysWhatTheReaderTurnedOff(): void
    {
        $registry = $this->conUnoApagado();
        $inspection = new PluginInspection(
            $registry,
            [ProveedorRicoFixture::class],
            null,
            null,
            null,
            baseline: $this->baseline(['ProveedorRicoFixture']),
        );

        $r = $inspection->architecture([]);

        self::assertSame([], $r['plugins']);
        self::assertFalse($r['baseline']['unchanged'], 'el vacío es reciente y el reporte lo dice');
        self::assertSame(['ProveedorRicoFixture'], $r['baseline']['disabledSince']);
    }

    /** Una app sin plugins tiene un grafo que cierra por vacío. */
    public function testAnAppWithNoPluginsHasAGraphThatClosesByBeingEmpty(): void
    {
        $r = $this->inspection([])->architecture([]);

        self::assertTrue($r['ok']);
        self::assertSame([], $r['capabilities']);
    }

    /**
     * `deps` devuelve DATOS, no una tabla ya pintada.
     *
     * Devolvía la forma corta de cada capacidad —y `'—'` cuando la lista venía vacía— así que por
     * `--json` y por MCP un agente recibía un guion donde esperaba una lista. Pintar es de la
     * superficie; una operación que ya pintó le quitó la decisión a las otras tres (ADR-0035).
     */
    public function testDepsReturnsDataAndNotAnAlreadyPaintedTable(): void
    {
        $r = $this->inspection([ProveedorFixture::class, ConsumidorFixture::class])->deps([]);

        $porNombre = [];
        foreach ($r['loadOrder'] as $fila) {
            $porNombre[$fila['plugin']] = $fila;
        }

        self::assertSame(['Acme\\Fixtures\\CosaContract'], $porNombre['ProveedorFixture']['provides']);
        self::assertSame([], $porNombre['ProveedorFixture']['requires'], 'una lista vacía, no un guion');
        self::assertSame(['Acme\\Fixtures\\CosaContract'], $porNombre['ConsumidorFixture']['requires']);
    }

    /**
     * LO SUGERIDO SIN PROVEEDOR SE VE, Y SE DICE DE DÓNDE SACARLO.
     *
     * Una capacidad `suggests` sin proveedor NO abre el grafo —la app arranca degradada— y por eso
     * era invisible aquí: no la pedía nadie, así que no contaba como huérfana. Pero es justo el estado
     * que un agente puede arreglar, y no podía verlo: `repair` sólo abre para lo que el diagnóstico
     * nombra, y este reporte no nombraba nada.
     *
     * Se separa de `unsatisfied` porque son dos estados distintos: mezclarlos volvería urgente lo que
     * no lo es — o peor, cotidiano lo que sí.
     */
    public function testASuggestedCapabilityWithNoProviderIsDegradedAndRecommends(): void
    {
        $r = $this->inspection([SugerenteFixture::class])->architecture([]);

        self::assertTrue($r['ok'], 'sugerir algo que falta no abre el grafo');
        self::assertSame([], $r['unsatisfied']);
        self::assertSame(['surface.mcp'], $r['degraded']);
        // LA RECOMENDACIÓN SALE DE LA TABLA DEL RESOLVER, que es otro paquete y otra versión: este
        // pin admite `^0.5.2 || ^0.6` y sólo 0.6 conoce `surface.mcp`. Afirmar el valor exacto sería
        // afirmar algo que este paquete NO controla — falló así en la ceremonia de release, contra el
        // resolver de Packagist.
        //
        // Lo que sí es de aquí es la FORMA: si hay recomendación, viene en el shape que `repair`
        // acepta sin interpretar nada.
        foreach ($r['recommended'] as $accion) {
            self::assertSame('install-package', $accion['type']);
            self::assertSame('surface.mcp', $accion['for']);
            self::assertIsString($accion['package']);
        }
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

/** Sugiere una capacidad de distribución que nadie provee: la app arranca degradada. */
#[PluginMetadata(
    version: '1.0.0',
    author: 'Acme',
    site: 'https://example.com',
    name: 'SugerenteFixture',
    type: 'Service',
    suggests: ['surface.mcp'],
)]
final class SugerenteFixture
{
}
