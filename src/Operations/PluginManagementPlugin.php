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

namespace Milpa\Plugin\Operations;

use Milpa\Attributes\PluginMetadata;
use Milpa\Command\CommandProvider;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInstallerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Interfaces\Plugin\PluginsManagerInterface;
use Milpa\Plugin\Contracts\ActivationSafetyInterface;
use Milpa\Plugin\Contracts\StateBaselineInterface;
use Milpa\Plugin\Runtime\BootStateBaseline;
use Milpa\Plugin\Runtime\MetadataActivationSafety;
use Milpa\Plugin\Contracts\AppRoot;
use Milpa\Plugin\Contracts\PluginRegistryInterface;
use Milpa\Plugin\Activation\DeclaredPlugins;
use Milpa\Plugin\PluginBase;

/**
 * The plugin that makes plugin management reachable.
 *
 * It is the one plugin a host adds by hand; from there every other one can be
 * installed and toggled from whatever surface the host runs — terminal, admin
 * panel, or an MCP client — because the kernel discovers {@see operations()}
 * on any booted plugin and each projector materialises them into its own
 * shape.
 *
 * Both collaborators come from the container. The registry is required — with
 * no store there is nothing to manage. The installer is optional: a host that
 * never wired one still lists and toggles what it has, and simply does not
 * offer the operations that would reach the network.
 */
#[PluginMetadata(
    version: '1.0.0',
    author: 'Rodrigo Vicente - TeamX Agency',
    site: 'https://teamx.agency',
    name: 'PluginManagement',
    type: 'Mixed',
)]
final class PluginManagementPlugin extends PluginBase implements CommandProvider, PluginInterface
{
    public function __construct(DIContainerInterface $container)
    {
        parent::__construct($container);
    }

    /**
     * Nothing to boot: this plugin contributes operations, it does not run.
     */
    public function boot(): void
    {
    }

    /**
     * Nothing to set up: this plugin owns no data of its own.
     */
    public function install(): void
    {
    }

    /**
     * Nothing to tear down — removing this plugin removes only the ability
     * to manage the others, never the others themselves.
     */
    public function uninstall(): void
    {
    }

    /**
     * Nothing to switch on: the operations appear because the kernel found
     * them, not because anything was started here.
     */
    public function enable(): void
    {
    }

    /**
     * Nothing to switch off.
     */
    public function disable(): void
    {
    }

    /**
     * The plugin-management operations, built against whatever this host
     * wired: the registry is required, the installer is not.
     *
     * @return list<\Milpa\Command\Operation>
     *
     * @throws \RuntimeException when no registry is in the container
     */
    public function operations(): array
    {
        // tryGet, not get: an absent registry is a wiring mistake this method
        // has something to say about, not a container exception to leak.
        $registry = $this->tryGetService(PluginRegistryInterface::class);
        if (!$registry instanceof PluginRegistryInterface) {
            throw new \RuntimeException(
                'PluginManagement needs a ' . PluginRegistryInterface::class . ' in the container.',
            );
        }

        $installer = $this->tryGetService(PluginInstallerInterface::class);

        // The declared list comes from the container, where `ActivePlugins::wire()`
        // put the very array the kernel booted from. A host that wired the
        // registry by hand may not have it; then only store records are known,
        // which is exactly what such a host has.
        $declared = $this->tryGetService(DeclaredPlugins::class);

        // La comprobación de seguridad al apagar. Un host que use `PluginsManager` la trae en él; uno
        // que arranque por `Kernel` —la forma de toda app generada— no tiene ese gestor, así que se
        // arma desde lo que SÍ declara: sus clases de plugin.
        //
        // El comentario que estaba aquí decía que un host sin cablear «pierde el aviso, no la
        // capacidad». Medido, eso significaba que la AUSENCIA de la infraestructura de seguridad
        // AMPLIABA la autoridad de una operación destructiva, y con ella se pudo dejar una app sin
        // arrancar. Ahora la ausencia se nota: sin evaluador, `plugins.disable` se niega
        // ({@see PluginOperations}) y queda la vía de recuperación, que exige confirmación.
        $manager = $this->tryGetService(PluginsManagerInterface::class);
        $safety = $manager instanceof ActivationSafetyInterface ? $manager : null;
        if ($safety === null && $declared instanceof DeclaredPlugins && $declared->classes !== []) {
            $safety = new MetadataActivationSafety(
                $declared->classes,
                $registry,
            );
        }

        // La raíz de la app la dice el host o no la dice nadie: contarla desde este archivo apuntaría
        // adentro de `vendor/` en cuanto el paquete se instale de verdad. Sin ella, las dos que tocan
        // disco no se ofrecen.
        $root = $this->tryGetService(AppRoot::class);

        // CON QUÉ ESTADO EMPEZÓ QUIEN LEE. Si el host declaró una —una sesión que abarca varios
        // procesos, digamos— manda la suya. Si no, se toma AQUÍ, y aquí es el único momento posible:
        // este método corre durante el arranque, mientras se recogen las operaciones, o sea antes de
        // que nadie haya podido llamar a `plugins.disable`.
        //
        // El primer intento de esto lo registraba en el contenedor desde la vuelta del agente, y
        // llegaba TARDE: para entonces las operaciones ya estaban armadas con la referencia vieja, y
        // el reporte salía con `baseline: null` en una vuelta que sí tenía línea base. Lo encontró un
        // control positivo del instrumento —apagar un plugin a mano y mirar el reporte— y no una
        // prueba: las pruebas pasaban las tres, porque probaban las piezas y no el orden.
        $baseline = $this->tryGetService(StateBaselineInterface::class);
        if (!$baseline instanceof StateBaselineInterface) {
            $baseline = BootStateBaseline::capture(new PluginInspection(
                $registry,
                $declared instanceof DeclaredPlugins ? $declared->classes : [],
            ));
        }

        return (new PluginOperations(
            $registry,
            $installer instanceof PluginInstallerInterface ? $installer : null,
            $declared instanceof DeclaredPlugins ? $declared->classes : [],
            $safety,
            $root instanceof AppRoot ? $root->path : null,
            $baseline,
        ))->operations();
    }
}
