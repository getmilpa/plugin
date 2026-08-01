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

namespace Milpa\Plugin\Runtime;

use Milpa\Plugin\Contracts\StateBaselineInterface;
use Milpa\Plugin\Operations\PluginInspection;

/**
 * La línea base por default: qué estaba encendido cuando arrancó este proceso.
 *
 * ── POR QUÉ EL ARRANQUE Y NO OTRO MOMENTO ───────────────────────────────────────────────────────
 *
 * Porque es el único que se puede tomar con la garantía que hace falta: **nadie ha llamado todavía a
 * `plugins.disable`**. Se captura mientras se recogen las operaciones, antes de que exista una
 * superficie por la que pedir un apagado.
 *
 * Y da la semántica correcta en las dos formas de usar el sistema, sin distinguirlas:
 *
 * - un `coa plugins:disable` y luego un `coa plugins:architecture` son dos procesos, así que el
 *   segundo arranca con lo que ya está: `unchanged: true`, y es cierto;
 * - una vuelta de agente es UN proceso, así que apagar a media vuelta y volver a leer sale como lo
 *   que es: un estado que el propio lector cambió.
 *
 * ── LA CAPTURA ES ANSIOSA, Y ESO ES EL PUNTO ────────────────────────────────────────────────────
 *
 * Una versión perezosa se contestaría la primera vez que alguien preguntara —posiblemente ya después
 * de un apagado— y devolvería como «el principio» un estado que el lector ya cambió. Sería el mismo
 * error que este objeto existe para evitar, cometido por el objeto mismo.
 *
 * Un host que sepa fechar mejor —una sesión que abarca varias invocaciones— declara su propia
 * {@see StateBaselineInterface} y ésta no se usa.
 */
final readonly class BootStateBaseline implements StateBaselineInterface
{
    /** @param list<string> $encendidos */
    private function __construct(private array $encendidos)
    {
    }

    /**
     * Se toma del MISMO cálculo que después reporta la arquitectura.
     *
     * Deliberadamente el mismo y no uno paralelo: dos formas de contar «qué está activo» divergen, y
     * el día que divergieran esto reportaría cambios que nadie hizo — un instrumento que inventa
     * movimiento es peor que no tenerlo.
     */
    public static function capture(PluginInspection $inspection): self
    {
        $nombres = [];
        foreach ($inspection->active() as $activo) {
            if (\is_string($activo['name'] ?? null)) {
                $nombres[] = $activo['name'];
            }
        }

        return new self(array_values(array_unique($nombres)));
    }

    /**
     * Nunca `null`: si esto existe, el proceso arrancó y su estado se capturó. El `null` del contrato
     * es para hosts que no pueden fechar nada.
     *
     * @return list<string>
     */
    public function enabledAtBaseline(): array
    {
        return $this->encendidos;
    }

    /**
     * Qué momento es la línea base, en palabras que quepan dentro de una frase del reporte.
     *
     * Se redacta como subordinada porque el reporte la mete en dos: «desde ___ se apagó X» y «que ya
     * no es el estado con ___».
     */
    public function baselineLabel(): string
    {
        return 'que empezó esta vuelta';
    }
}
