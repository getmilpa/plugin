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

namespace Milpa\Plugin\Contracts;

/**
 * Dice con qué estado empezó quien está mirando, para que un reporte no pueda leerse como si
 * describiera un mundo que el propio lector ya cambió.
 *
 * ── POR QUÉ EXISTE, CON MEDICIÓN ────────────────────────────────────────────────────────────────
 *
 * De las 12 corridas de Q-P17-J que leyeron el reporte de arquitectura DESPUÉS de haber apagado un
 * plugin, 10 contestaron mal. Las que no mutaron nada acertaron el 53 %; las que mutaron, el 18 %.
 *
 * Y el reporte no mentía. Apagado `FixtureAlmacen`, el campo `breaksIfDisabled` de `FixtureEspejo`
 * avisaba —correctamente— que apagarlo rompería a `FixtureConsumidor`: un dato exacto, sobre una
 * acción FUTURA y sobre OTRO plugin. El agente lo citó como la consecuencia observada de lo que él
 * acababa de hacer, y escribió una respuesta falsa sin que nadie le hubiera dicho nada falso.
 *
 * Lo que faltaba no era verdad. Era desde qué estado se afirmaba. Ver
 * `docs/library/settlement-q-p17j.md`.
 *
 * ── POR QUÉ ES UN CONTRATO Y NO UNA MARCA DE TIEMPO ─────────────────────────────────────────────
 *
 * Porque «el principio» sólo lo sabe quien abrió la vuelta: una sesión de agente, una pantalla que
 * acaba de cargar, un comando que empezó. Este paquete no puede fecharlo sin inventar de qué momento
 * habla. Y si nadie contesta, el reporte lo dice —`baseline: null`, «no se pudo preguntar»— en vez de
 * afirmar que nada cambió, que es justo la afirmación que produjo el error.
 */
interface StateBaselineInterface
{
    /**
     * Los nombres de los plugins que estaban encendidos cuando empezó la vuelta, o `null` si no hay
     * una vuelta de la que hablar.
     *
     * @return list<string>|null
     */
    public function enabledAtBaseline(): ?array;

    /**
     * Qué momento es ése, en palabras que quepan en el reporte.
     *
     * Va como texto porque el lector es un modelo tanto como una persona, y «t0» no le dice nada a
     * ninguno de los dos. Se redacta como una subordinada que complete estas dos frases, porque el
     * reporte la mete en las dos:
     *
     *   «desde ___ se apagó FixtureAlmacen»
     *   «que ya no es el estado con ___»
     *
     * O sea «que empezó esta vuelta», «que se cargó esta pantalla». No «t0», y tampoco una oración
     * completa: una que termine en punto rompe las dos.
     */
    public function baselineLabel(): string;
}
