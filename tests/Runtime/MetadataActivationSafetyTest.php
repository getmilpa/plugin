<?php

declare(strict_types=1);

namespace Milpa\Plugin\Tests\Runtime;

use Milpa\Attributes\PluginMetadata;
use Milpa\Plugin\Runtime\MetadataActivationSafety;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[PluginMetadata(version: '1.0.0', author: 'a', site: 'https://e.com', name: 'Almacen', type: 'Service',
    provides: [['id' => 'demo.almacen.v1', 'interface' => 'D\\A', 'contractVersion' => '1.0.0']])]
final class SafetyAlmacen
{
}

#[PluginMetadata(version: '1.0.0', author: 'a', site: 'https://e.com', name: 'Espejo', type: 'Service',
    provides: [['id' => 'demo.almacen.v1', 'interface' => 'D\\A', 'contractVersion' => '1.0.0']])]
final class SafetyEspejo
{
}

#[PluginMetadata(version: '1.0.0', author: 'a', site: 'https://e.com', name: 'Consumidor', type: 'Service',
    requires: [['id' => 'demo.almacen.v1', 'interface' => 'D\\A', 'constraint' => '^1.0']])]
final class SafetyConsumidor
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
}
