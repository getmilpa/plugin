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
 * Dónde vive la app que instaló este paquete.
 *
 * ── POR QUÉ SE PREGUNTA EN VEZ DE CALCULARLA ────────────────────────────────────────────────────
 *
 * Porque contar directorios hacia arriba desde el propio archivo funciona mientras el código vive
 * dentro del host y deja de funcionar en cuanto no: instalado por Composer, `dirname(__DIR__, 3)`
 * apunta a algún lugar DENTRO de `vendor/`, un directorio que el siguiente `composer install` puede
 * borrar. Ya mordió una vez, en `milpa/admin`, y la salida fue la misma que aquí: un puerto.
 *
 * ── QUÉ PASA SI NADIE LA REGISTRA ───────────────────────────────────────────────────────────────
 *
 * Las dos operaciones que tocan disco —verificar un manifiesto, regenerar `milpa.lock`— NO se
 * ofrecen. No se inventa una raíz ni se sirve un botón que truena al apretarlo: un host que no dijo
 * dónde vive simplemente no tiene esas dos, y las otras siguen funcionando.
 */
final readonly class AppRoot
{
    public function __construct(public string $path)
    {
    }
}
