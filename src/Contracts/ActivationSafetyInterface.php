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
 * Contesta si apagar un plugin dejaría a este host sin poder arrancar.
 *
 * ── POR QUÉ EXISTE ──────────────────────────────────────────────────────────────────────────────
 *
 * Porque apagar puede ser irreversible EN LA PRÁCTICA aunque no lo sea en la teoría. Si el perfil del
 * host requiere una capacidad que sólo ese plugin provee, el resolver bloquea el siguiente arranque —
 * correctamente, un grafo abierto no debe arrancar— y a partir de ahí `plugins.enable` tampoco corre,
 * porque necesita que el host arranque. Quien lo apagó se queda sin la herramienta con que lo
 * encendería.
 *
 * Pasó de verdad, probando otra cosa: apagar un plugin dejó un host inarrancable y hubo que
 * reencenderlo escribiendo directo en su base de datos. No es un caso hipotético ni raro — es lo que
 * pasa la primera vez que alguien apaga el plugin equivocado.
 *
 * ── POR QUÉ ES UN CONTRATO Y NO UNA COMPROBACIÓN DIRECTA ────────────────────────────────────────
 *
 * Sólo el host sabe qué perfil tiene que satisfacer. Un paquete que resolviera el grafo por su cuenta
 * tendría que adivinar ese perfil, y un perfil inventado bloquearía arranques que hoy funcionan o
 * dejaría pasar los que no. Así que se pregunta a quien sí sabe, y si nadie contesta —un host que no
 * declara perfil— la operación sigue como antes: no saber no autoriza a inventar.
 */
interface ActivationSafetyInterface
{
    /**
     * El motivo por el que apagar `$pluginName` dejaría el grafo bloqueado, o `null` si no lo haría.
     *
     * Devuelve el MOTIVO y no un booleano a propósito: quien recibe la negativa necesita saber qué
     * capacidad se quedaría sin proveedor para decidir qué instalar antes. Un `false` lo obliga a ir
     * a buscarlo, y la información ya estaba aquí.
     */
    public function blockingReasonWithout(string $pluginName): ?string;
}
