<?php

declare(strict_types=1);

namespace Milpa\Plugin\Tests\Runtime;

use Milpa\Attributes\PluginMetadata;
use Milpa\Plugin\Runtime\MetadataActivationSafety;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[PluginMetadata(
    version: '1.0.0',
    author: 'a',
    site: 'https://e.com',
    name: 'Almacen',
    type: 'Service',
    provides: [['id' => 'demo.almacen.v1', 'interface' => 'D\\A', 'contractVersion' => '1.0.0']]
)]
final class SafetyAlmacen
{
}

#[PluginMetadata(
    version: '1.0.0',
    author: 'a',
    site: 'https://e.com',
    name: 'Espejo',
    type: 'Service',
    provides: [['id' => 'demo.almacen.v1', 'interface' => 'D\\A', 'contractVersion' => '1.0.0']]
)]
final class SafetyEspejo
{
}

#[PluginMetadata(
    version: '1.0.0',
    author: 'a',
    site: 'https://e.com',
    name: 'Consumidor',
    type: 'Service',
    requires: [['id' => 'demo.almacen.v1', 'interface' => 'D\\A', 'constraint' => '^1.0']]
)]
final class SafetyConsumidor
{
}

// A propósito SIN #[PluginMetadata]: no declara `requires`, así que agregarlo no puede abrir el grafo.
final class SafetySinMetadata
{
}

/**
 * El evaluador que le faltaba a toda app generada.
 *
 * El caso de abajo NO es inventado: se reprodujo contra la app real de `milpa/framework` mientras se
 * medía otra cosa, y dejó el host sin arrancar. Un agente en modo auto, ante la pregunta «qué deja de
 * funcionar si deshabilito X», apagó los dos proveedores.
 */
#[CoversClass(MetadataActivationSafety::class)]
final class MetadataActivationSafetyTest extends TestCase
{
    private const TODOS = [SafetyAlmacen::class, SafetyEspejo::class, SafetyConsumidor::class];

    public function testApagarUnProveedorDeDosNoBloquea(): void
    {
        $safety = new MetadataActivationSafety(self::TODOS);

        self::assertNull($safety->blockingReasonWithout('Almacen'), 'queda el espejo');
    }

    public function testApagarElULTIMOProveedorSiBloquea(): void
    {
        // Con el espejo ya fuera del conjunto en curso, apagar el almacén deja al consumidor sin nada.
        $safety = new MetadataActivationSafety([SafetyAlmacen::class, SafetyConsumidor::class]);

        $motivo = $safety->blockingReasonWithout('Almacen');

        self::assertNotNull($motivo);
        self::assertStringContainsString('demo.almacen.v1', $motivo, 'dice QUÉ capacidad, no sólo que no se puede');
    }

    public function testApagarAlgoQueNadieRequiereNoBloquea(): void
    {
        $safety = new MetadataActivationSafety(self::TODOS);

        self::assertNull($safety->blockingReasonWithout('Consumidor'));
    }

    public function testUnPluginQueNoEstaEnLaListaNoBloqueaNada(): void
    {
        // Apagar lo que no está declarado no cambia el grafo, y contestar un motivo ahí sería inventar
        // un problema — el control negativo que evita que esto niegue por sistema.
        $safety = new MetadataActivationSafety(self::TODOS);

        self::assertNull($safety->blockingReasonWithout('NoExiste'));
    }

    // ── la otra mitad: agregar (greenhouse decisions/0178) ──────────────────────────────────────
    public function testAgregarUnConsumidorConSuProveedorPresenteNoBloquea(): void
    {
        // El grafo en curso tiene el almacén; agregar el consumidor cierra.
        $safety = new MetadataActivationSafety([SafetyAlmacen::class]);

        self::assertNull($safety->blockingReasonWith(SafetyConsumidor::class));
    }

    public function testAgregarUnConsumidorSinProveedorSiBloquea(): void
    {
        // Nadie provee demo.almacen.v1: registrar el consumidor abriría el grafo — se atrapa AQUÍ, en el
        // gate, no en el siguiente arranque. Es el brick que el agente causó, prevenido.
        $safety = new MetadataActivationSafety([]);

        $motivo = $safety->blockingReasonWith(SafetyConsumidor::class);

        self::assertNotNull($motivo);
        self::assertStringContainsString('demo.almacen.v1', $motivo, 'dice QUÉ capacidad faltaría');
    }

    public function testAgregarUnPluginSinMetadataNoBloquea(): void
    {
        // Sin #[PluginMetadata] no hay `requires`: agregarlo es seguro. Control que separa «nada que
        // comprobar» de «no se pudo» — este no niega, y por eso el guardia no es un muro.
        $safety = new MetadataActivationSafety([SafetyAlmacen::class]);

        self::assertNull($safety->blockingReasonWith(SafetySinMetadata::class));
    }

    public function testAgregarUnProveedorNoBloquea(): void
    {
        $safety = new MetadataActivationSafety([]);

        self::assertNull($safety->blockingReasonWith(SafetyAlmacen::class));
    }
}
