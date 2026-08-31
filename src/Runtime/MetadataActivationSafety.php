<?php

/**
 * This file is part of Milpa Plugin — the plugin ecosystem of the Milpa PHP framework.
 *
 * (c) Rodrigo Vicente - TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/plugin
 */

declare(strict_types=1);

namespace Milpa\Plugin\Runtime;

use Milpa\Attributes\PluginMetadata;
use Milpa\Plugin\Activation\ActivePlugins;
use Milpa\Plugin\Contracts\ActivationSafetyInterface;
use Milpa\Plugin\Contracts\PluginRegistryInterface;
use Milpa\Resolver\Report\ResolutionStatus;

/**
 * Contesta si apagar un plugin dejaría el grafo sin cerrar, para un host que declara sus plugins como
 * clases — que es la forma de toda app generada por `milpa/framework`.
 *
 * ## Por qué existe
 *
 * `PluginsManager` ya implementaba {@see ActivationSafetyInterface}, pero una app que arranca por
 * `Milpa\Runtime\Kernel` **no usa ese gestor**: declara clases en `config/plugins.php` y el kernel las
 * resuelve. Así que en la plantilla que se reparte no había NINGUNA implementación, `PluginOperations`
 * recibía `safety: null`, y la comprobación no se saltaba por decisión — se saltaba por ausencia.
 *
 * Medido: dos plugins proveyendo la misma capacidad y uno requiriéndola; apagar el primero pasa
 * (queda el otro), apagar el segundo también pasaba, y la app dejaba de arrancar. A partir de ahí
 * `plugins.enable` tampoco corre, porque necesita que el host arranque — hay que editar el estado a
 * mano. El docblock de {@see ActivationSafetyInterface} dice que eso ya pasó de verdad una vez.
 *
 * ## Qué pregunta, exactamente
 *
 * Resuelve el grafo **sin** el plugin en cuestión y mira si queda bloqueado. Nada más: no opina sobre
 * si el plugin es importante, ni sobre cuántos lo usan. La pregunta es si el host seguiría pudiendo
 * arrancar, que es la única cuya respuesta es irreversible en la práctica.
 *
 * Un plugin que no está en la lista no bloquea nada: apagar lo que no está declarado no cambia el
 * grafo, y contestar un motivo ahí sería inventar un problema.
 */
final class MetadataActivationSafety implements ActivationSafetyInterface
{
    /** @var list<class-string> */
    private array $declared;

    private ?PluginRegistryInterface $registry;

    private MetadataGraphResolver $resolver;

    /**
     * @param list<class-string>           $declared Las clases que el host declara en `config/plugins.php`.
     * @param PluginRegistryInterface|null $registry Quién sabe cuáles están ENCENDIDAS. Sin él sólo se
     *                                               puede razonar sobre lo declarado, que es más
     *                                               permisivo — ver {@see enCurso()}.
     */
    public function __construct(
        array $declared,
        ?PluginRegistryInterface $registry = null,
        ?MetadataGraphResolver $resolver = null,
    ) {
        $this->declared = $declared;
        $this->registry = $registry;
        $this->resolver = $resolver ?? new MetadataGraphResolver();
    }

    /**
     * El motivo por el que apagar `$pluginName` dejaría este host sin poder arrancar, o `null`.
     *
     * Resuelve el grafo SIN ese plugin y devuelve la razón que el resolver dé. Falla cerrado: si la
     * resolución no se puede hacer, contesta con un motivo bloqueante en vez de con `null` — decir
     * «no se rompe nada» cuando en realidad no se pudo preguntar es la peor de las respuestas.
     */
    public function blockingReasonWithout(string $pluginName): ?string
    {
        $restantes = [];
        $quitado = false;

        foreach ($this->enCurso() as $clase) {
            $meta = $this->metadataOf($clase);
            if ($meta === null) {
                continue;
            }
            if ($meta->name === $pluginName) {
                $quitado = true;

                continue;
            }
            $restantes[] = [
                'name' => $meta->name,
                'version' => $meta->version,
                'type' => $meta->type,
                'provides' => array_values($meta->provides),
                'requires' => array_values($meta->requires),
                'suggests' => array_values($meta->suggests),
            ];
        }

        if (!$quitado) {
            return null;
        }

        try {
            $reporte = $this->resolver->diagnose($restantes);
        } catch (\Throwable $e) {
            // NO poder comprobar no es haber comprobado. Se devuelve un motivo —y por lo tanto se
            // NIEGA el apagado— en vez de dejar pasar: la ausencia del componente que demuestra que
            // una mutación es segura se interpreta como incapacidad de autorizar, no como permiso.
            // Quien necesite apagar de todos modos tiene la vía de recuperación, que exige
            // confirmación y queda registrada como override.
            return 'no se pudo comprobar si el grafo seguiría cerrando sin ' . $pluginName
                . ' (' . $e->getMessage() . '), así que no se autoriza el apagado.';
        }

        if ($reporte->status !== ResolutionStatus::Blocked) {
            return null;
        }

        return $reporte->firstLearnableLine()
            ?? 'el grafo de arquitectura quedaría bloqueado; el resolver no reportó un error legible.';
    }

    /**
     * Resuelve el grafo CON `$newPluginClass` agregado y devuelve la razón que el resolver dé si quedaría
     * bloqueado. La otra mitad de {@see self::blockingReasonWithout()}: agregar trae `requires`, y el
     * invariante es uno —el grafo nunca se deja abierto por una mutación (greenhouse decisions/0178).
     * Falla cerrado: sin metadata legible o sin poder resolver, contesta con un motivo, no con `null`.
     */
    public function blockingReasonWith(string $newPluginClass): ?string
    {
        $nuevo = $this->metadataOf($newPluginClass);
        if ($nuevo === null) {
            // Sin `#[PluginMetadata]` no hay `requires` que declarar: agregar un plugin así no puede
            // abrir el grafo, así que no hay nada que bloquear. No es «no se pudo comprobar» —es «no
            // había nada que comprobar». (Un `requires` sólo viaja en el atributo.)
            return null;
        }

        $plugins = [];
        foreach ($this->enCurso() as $clase) {
            $meta = $this->metadataOf($clase);
            if ($meta === null) {
                continue;
            }
            $plugins[] = [
                'name' => $meta->name,
                'version' => $meta->version,
                'type' => $meta->type,
                'provides' => array_values($meta->provides),
                'requires' => array_values($meta->requires),
                'suggests' => array_values($meta->suggests),
            ];
        }
        $plugins[] = [
            'name' => $nuevo->name,
            'version' => $nuevo->version,
            'type' => $nuevo->type,
            'provides' => array_values($nuevo->provides),
            'requires' => array_values($nuevo->requires),
            'suggests' => array_values($nuevo->suggests),
        ];

        try {
            $reporte = $this->resolver->diagnose($plugins);
        } catch (\Throwable $e) {
            return 'no se pudo comprobar si el grafo cerraría con ' . $newPluginClass
                . ' (' . $e->getMessage() . '), así que no se autoriza el registro.';
        }

        if ($reporte->status !== ResolutionStatus::Blocked) {
            return null;
        }

        return $reporte->firstLearnableLine()
            ?? 'el grafo de arquitectura quedaría bloqueado; el resolver no reportó un error legible.';
    }

    /**
     * Las clases que el PRÓXIMO arranque cargaría: las declaradas y encendidas.
     *
     * Y no las declaradas a secas, que fue el primer intento y estaba mal: un plugin apagado sigue
     * declarado, así que resolver sobre la lista completa veía un proveedor que el arranque no va a
     * cargar. Reproducido — con dos proveedores, apagar el primero y luego el segundo pasaba, porque
     * el primero seguía contando. El grafo que hay que resolver es el que va a existir.
     *
     * Sin registro se cae a lo declarado: es lo único que se sabe, y es MÁS permisivo, así que quien
     * llame sin registro obtiene una comprobación más débil —no ninguna—. Vale la pena decirlo porque
     * una comprobación más débil que no se anuncia es la clase de silencio que este arreglo vino a
     * quitar.
     *
     * @return list<class-string>
     */
    private function enCurso(): array
    {
        return $this->registry === null
            ? $this->declared
            : ActivePlugins::resolve($this->declared, $this->registry);
    }

    /** El `#[PluginMetadata]` de una clase, o `null` si no la tiene o no se puede cargar. */
    private function metadataOf(string $clase): ?PluginMetadata
    {
        if (!class_exists($clase)) {
            return null;
        }

        $atributos = (new \ReflectionClass($clase))->getAttributes(PluginMetadata::class);

        return $atributos === [] ? null : $atributos[0]->newInstance();
    }
}
