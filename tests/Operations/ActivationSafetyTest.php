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

use Milpa\Plugin\Contracts\ActivationSafetyInterface;
use Milpa\Plugin\Contracts\PluginRecord;
use Milpa\Plugin\Operations\PluginOperations;
use Milpa\Plugin\Registry\InMemoryPluginRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Apagar un plugin puede ser irreversible EN LA PRÁCTICA, y por eso se comprueba antes.
 *
 * Si el perfil del host requiere una capacidad que sólo ese plugin provee, el resolver bloquea el
 * siguiente arranque —con razón— y a partir de ahí `plugins.enable` tampoco corre, porque necesita
 * que el host arranque. Quien apagó se queda sin la herramienta con que encendería.
 *
 * No es hipotético: pasó, y hubo que reencender el plugin escribiendo directo en la base de datos del
 * host. Estas pruebas fijan que no vuelva a poder pasar en silencio.
 */
final class ActivationSafetyTest extends TestCase
{
    private function registry(): InMemoryPluginRegistry
    {
        $r = new InMemoryPluginRegistry();
        $r->register(new PluginRecord(
            name: 'CriticalPlugin',
            version: '1.0.0',
            author: 'Acme',
            site: 'https://example.com',
            type: 'Service',
            installed: true,
            enabled: true,
        ));

        return $r;
    }

    /** @param string|null $motivo lo que la comprobación contesta */
    private function safety(?string $motivo): ActivationSafetyInterface
    {
        return new class ($motivo) implements ActivationSafetyInterface {
            public function __construct(private readonly ?string $motivo)
            {
            }

            public function blockingReasonWithout(string $pluginName): ?string
            {
                return $this->motivo;
            }

            public function blockingReasonWith(string $newPluginClass): ?string
            {
                return $this->motivo;
            }
        };
    }

    private function disable(PluginOperations $ops, string $nombre): mixed
    {
        foreach ($ops->operations() as $op) {
            if ($op->name === 'plugins.disable') {
                $handler = $op->handler;
                self::assertIsCallable($handler);

                return $handler(['name' => $nombre]);
            }
        }

        self::fail('no hay operación plugins.disable');
    }

    /**
     * Un apagado que dejaría el host sin arrancar se NIEGA, y la negativa lleva el motivo.
     *
     * El motivo importa tanto como la negativa: quien la recibe necesita saber qué capacidad se
     * quedaría sin proveedor para decidir qué instalar antes. «No se puede» lo manda a buscarlo, y la
     * información ya estaba ahí.
     */
    public function test_apagar_algo_que_dejaria_el_host_sin_arrancar_se_niega_con_el_motivo(): void
    {
        $registry = $this->registry();
        $ops = new PluginOperations($registry, null, [], $this->safety('falta un proveedor de acme.capacidad.v1'));

        try {
            $this->disable($ops, 'CriticalPlugin');
            self::fail('debería haberse negado');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('acme.capacidad.v1', $e->getMessage());
            self::assertStringContainsString('Nothing was changed', $e->getMessage());
        }

        $registro = $registry->find('CriticalPlugin');
        self::assertNotNull($registro);
        self::assertTrue($registro->enabled, 'y de verdad no cambió nada');
    }

    /** Cuando la comprobación dice que no hay problema, apagar funciona como siempre. */
    public function test_un_apagado_seguro_sigue_funcionando(): void
    {
        $registry = $this->registry();
        $ops = new PluginOperations($registry, null, [], $this->safety(null));

        $this->disable($ops, 'CriticalPlugin');

        $registro = $registry->find('CriticalPlugin');
        self::assertNotNull($registro);
        self::assertFalse($registro->enabled);
    }

    /**
     * Sin comprobación cableada, apagar SE NIEGA. Esta prueba está invertida a propósito.
     *
     * Antes decía lo contrario, con este argumento: «no saber no autoriza a negar». Medido, ese
     * argumento tenía el signo al revés — permitía que la AUSENCIA de la infraestructura de seguridad
     * AMPLIARA la autoridad de una operación destructiva. Se reprodujo: dos proveedores de una
     * capacidad, apagar uno, apagar el otro, y la app dejó de arrancar. A partir de ahí `enable`
     * tampoco corre, porque necesita que el host arranque.
     *
     * La regla correcta es la misma que este repositorio ya aplica a las comprobaciones: **«no pude
     * mirar» no es «miré y nada se rompe»** — ahora aplicada a autoridad operativa, no a un gate.
     */
    public function test_sin_comprobacion_cableada_apagar_se_niega(): void
    {
        $registry = $this->registry();
        $ops = new PluginOperations($registry);

        try {
            $this->disable($ops, 'CriticalPlugin');
            self::fail('debió negarse');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('MILPA_PLUGIN_SAFETY_UNAVAILABLE', $e->getMessage());
            self::assertStringContainsString('plugins.simulate', $e->getMessage(), 'nombra la vía que NO muta');
            self::assertStringContainsString('disable-unsafe', $e->getMessage(), 'y la de recuperación');
        }

        // Y NADA se modificó: negar y además haber cambiado el estado sería lo peor de las dos.
        self::assertNull($registry->find('CriticalPlugin')?->enabled ? null : $registry->find('CriticalPlugin'));
    }

    /**
     * La vía de recuperación sí apaga, y lo deja registrado como override.
     *
     * Existe porque un host deliberadamente mínimo, o uno que hay que recuperar, tiene que poder
     * apagar. Lo que no puede es hacerlo por el mismo camino y con la misma autoridad que una
     * operación ordinaria: es otra operación, exige confirmación, y no se ofrece a un agente.
     */
    public function test_la_via_de_recuperacion_apaga_y_lo_dice(): void
    {
        $registry = $this->registry();
        $ops = new PluginOperations($registry);

        $recuperacion = null;
        foreach ($ops->operations() as $op) {
            if ($op->name === 'plugins.disable-unsafe') {
                $recuperacion = $op;
            }
        }

        self::assertNotNull($recuperacion, 'la vía de recuperación existe');
        self::assertTrue($recuperacion->requiresConfirmation, 'ninguna autonomía pre-aprueba una firma');
        self::assertSame(['cli'], $recuperacion->surfaces, 'fuera del catálogo que ve un agente');

        $resultado = ($recuperacion->handler)(['name' => 'CriticalPlugin']);

        self::assertFalse($resultado['enabled']);
        self::assertFalse($resultado['safety']['evaluated'], 'no se evaluó, y el resultado lo dice');
        self::assertTrue($resultado['safety']['override']);
        self::assertFalse($registry->find('CriticalPlugin')?->enabled);
    }

    /** ENCENDER nunca se comprueba: agregar un proveedor no puede quitarle uno a nadie. */
    public function test_encender_no_consulta_la_comprobacion(): void
    {
        $consultada = false;
        $safety = new class ($consultada) implements ActivationSafetyInterface {
            public function __construct(private bool &$consultada)
            {
            }

            public function blockingReasonWithout(string $pluginName): ?string
            {
                $this->consultada = true;

                return 'jamás debería consultarse al encender';
            }

            public function blockingReasonWith(string $newPluginClass): ?string
            {
                return null;
            }
        };

        $registry = $this->registry();
        $ops = new PluginOperations($registry, null, [], $safety);

        foreach ($ops->operations() as $op) {
            if ($op->name === 'plugins.enable') {
                $handler = $op->handler;
                self::assertIsCallable($handler);
                $handler(['name' => 'CriticalPlugin']);
            }
        }

        self::assertFalse($consultada);
    }
}
