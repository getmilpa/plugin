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
use Milpa\Command\Operation;
use Milpa\Interfaces\Plugin\PluginInstallerInterface;
use Milpa\Plugin\Contracts\PluginRecord;
use Milpa\Plugin\Contracts\ActivationSafetyInterface;
use Milpa\Plugin\Contracts\PluginRegistryInterface;
use Milpa\Plugin\Contracts\StateBaselineInterface;

/**
 * Managing plugins, expressed once as operations.
 *
 * Listing, enabling, installing and removing a plugin were CLI commands: to
 * reach them from anywhere else — an admin panel, an MCP client, a catalog —
 * someone had to write the same logic again behind a controller. As operations
 * they are defined once and every surface projector materialises them, so the
 * panel and the terminal cannot drift apart because there is only one of them.
 *
 * What a host gets depends on what it wired. With only a registry, the four
 * read-and-toggle operations exist. With an installer too, the three that reach
 * the network appear as well — a host that never wired one does not get an
 * `install` button that fails when pressed.
 *
 * A host has plugins from two places and these operations report both: what it
 * **declares in code** and what its **store** holds. A declared plugin has no
 * record until somebody switches it off — that is what keeps a store from
 * appearing in an app that never manages anything — so listing only records
 * would show an empty panel to an app that is running plugins right now, and
 * disabling one would fail with "not installed" for every plugin it has.
 */
final readonly class PluginOperations
{
    /**
     * La comprobación de seguridad al apagar es OPCIONAL y por contrato.
     *
     * Sólo el host sabe qué perfil de arquitectura tiene que satisfacer, así que este paquete no
     * puede resolverlo por su cuenta sin adivinarlo. Si nadie la cablea, apagar se comporta como
     * siempre: no saber no autoriza a inventar, ni a negar.
     *
     * @param list<class-string>             $declared The plugin classes the host declares in code.
     *                                                 They have no registry record until somebody
     *                                                 switches one off, so without them a
     *                                                 freshly-installed app reports that it has no
     *                                                 plugins while running two.
     * @param ActivationSafetyInterface|null $safety   quien contesta si apagar dejaría el host sin
     *                                                 arrancar; `null` deja el comportamiento previo
     * @param string|null                    $root     la raíz de la app. Sin ella, las dos
     *                                                 operaciones que tocan disco —verificar un
     *                                                 manifiesto, regenerar `milpa.lock`— no se
     *                                                 ofrecen: un paquete no adivina dónde vive quien
     *                                                 lo instala, y una superficie no debería pintar
     *                                                 un botón que truena al apretarlo
     */
    public function __construct(
        private PluginRegistryInterface $registry,
        private ?PluginInstallerInterface $installer = null,
        private array $declared = [],
        private ?ActivationSafetyInterface $safety = null,
        private ?string $root = null,
        // Con qué estado empezó quien lee. Sólo la usa el reporte de arquitectura, y viaja por aquí
        // porque este es el único lugar donde el host arma las operaciones. Ver
        // {@see StateBaselineInterface}.
        private ?StateBaselineInterface $baseline = null,
    ) {
    }

    /** Lo que mira sin tocar: el grafo, los manifiestos, las versiones publicadas. */
    private function inspection(): PluginInspection
    {
        return new PluginInspection(
            $this->registry,
            $this->declared,
            $this->root,
            $this->installer,
            $this->safety,
            baseline: $this->baseline,
        );
    }

    /**
     * Every operation this host can offer, in the order a surface should
     * present them: read first, then the toggles, then the three that reach
     * out for code.
     *
     * @return list<Operation>
     */
    public function operations(): array
    {
        $operations = [
            new Operation(
                name: 'plugins.list',
                description: 'List every installed plugin with its version, type and whether it boots.',
                handler: fn (array $input): array => $this->list($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => [
                        'enabledOnly' => [
                            'type' => 'boolean',
                            'description' => 'Only the plugins that currently boot.',
                            'default' => false,
                        ],
                    ],
                ],
                scopes: ['plugins:read'],
                path: '/plugins',
            ),
            new Operation(
                name: 'plugins.show',
                description: 'Everything the registry knows about one plugin.',
                handler: fn (array $input): array => $this->show($input),
                inputSchema: $this->nameSchema(),
                scopes: ['plugins:read'],
                path: '/plugins/show',
            ),
            new Operation(
                name: 'plugins.enable',
                description: 'Turn a plugin on: it boots from the next request or command.',
                handler: fn (array $input): array => $this->setEnabled($input, true),
                inputSchema: $this->nameSchema(),
                mutating: true,
                scopes: ['plugins:write'],
                path: '/plugins/enable',
            ),
            new Operation(
                name: 'plugins.disable',
                description: 'Turn a plugin off without removing it or its data.',
                handler: fn (array $input): array => $this->setEnabled($input, false),
                inputSchema: $this->nameSchema(),
                mutating: true,
                scopes: ['plugins:write'],
                path: '/plugins/disable',
            ),
            // La vía de RECUPERACIÓN, y es otra operación a propósito.
            //
            // Vive aparte y no como una bandera de `plugins.disable` porque una bandera se lee como
            // comodidad y se pasa sin pensar; una operación distinta hay que ir a buscarla. Y no
            // inventa una autoridad nueva: reusa la que ya existe —`requiresConfirmation`, que ninguna
            // autonomía pre-aprueba— así que un agente en modo `auto` se detiene aquí igual.
            //
            // `surfaces: ['cli']` la deja FUERA del catálogo que ve el agente: recuperar un host es
            // trabajo de quien tiene la terminal, no de quien corre dentro de él.
            new Operation(
                name: 'plugins.disable-unsafe',
                description: 'Recovery only: turn a plugin off WITHOUT the safety evaluation. May leave this host unable to boot.',
                handler: fn (array $input): array => $this->setEnabled($input, false, overridden: true),
                inputSchema: $this->nameSchema(),
                mutating: true,
                requiresConfirmation: true,
                scopes: ['plugins:write'],
                surfaces: ['cli'],
                path: '/plugins/disable-unsafe',
            ),
        ];

        // Las que MIRAN. Van después de las cuatro básicas y antes de las que salen a la red, que es
        // el orden en que una superficie debería presentarlas: ver, cambiar, y hasta el final traer
        // código de afuera.
        $operations[] = new Operation(
            name: 'plugins.deps',
            description: 'Whether the active plugin graph resolves, and in which order they would boot.',
            handler: fn (array $input): array => $this->inspection()->deps($input),
            inputSchema: ['type' => 'object', 'properties' => []],
            scopes: ['plugins:read'],
            path: '/plugins/deps',
        );

        // El GRAFO como dato (P17.2). Aparte de `deps` porque contestan preguntas distintas: `deps`
        // dice en qué orden arrancan —una pregunta de arranque— y esto dice quién provee qué, quién lo
        // usa, qué quedó sin dueño y qué se rompe si apagas algo, que son las preguntas de quien
        // OPERA el sistema. Antes había que leer plugin por plugin y cruzarlo a mano.
        $operations[] = new Operation(
            name: 'plugins.architecture',
            description: 'The capability graph as data: who provides what, who needs it, what is unsatisfied, and what breaks if you turn a plugin off.',
            handler: fn (array $input): array => $this->inspection()->architecture($input),
            inputSchema: ['type' => 'object', 'properties' => []],
            scopes: ['plugins:read'],
            path: '/plugins/architecture',
        );

        $operations[] = new Operation(
            name: 'plugins.simulate',
            description: 'What turning a plugin on would do, without turning it on.',
            handler: fn (array $input): array => $this->inspection()->simulate($input),
            inputSchema: [
                'type' => 'object',
                'properties' => ['plugin' => ['type' => 'string', 'description' => 'Plugin name, e.g. "MailPlugin".']],
                'required' => ['plugin'],
            ],
            scopes: ['plugins:read'],
            path: '/plugins/simulate',
        );

        if ($this->root !== null) {
            $operations[] = new Operation(
                name: 'plugins.verify',
                description: "Whether a plugin's milpa.json exists, validates, and matches its attribute.",
                handler: fn (array $input): array => $this->inspection()->verify($input),
                inputSchema: [
                    'type' => 'object',
                    'properties' => ['plugin' => ['type' => 'string', 'description' => 'Plugin name, e.g. "MailPlugin".']],
                    'required' => ['plugin'],
                ],
                scopes: ['plugins:read'],
                path: '/plugins/verify',
            );

            $operations[] = new Operation(
                name: 'plugins.lock',
                description: 'Regenerate milpa.lock from what the registry says is installed.',
                handler: fn (array $input): array => $this->inspection()->lock($input),
                inputSchema: ['type' => 'object', 'properties' => []],
                // Escribe un archivo, y lo dice. Sin firma: reconstruye de forma determinista desde el
                // registry, el resultado va a git, y quien lo corre suele estar arreglando justo esa
                // desincronización.
                mutating: true,
                scopes: ['plugins:write'],
                path: '/plugins/lock',
            );
        }

        if ($this->installer === null) {
            return $operations;
        }

        $operations[] = new Operation(
            name: 'plugins.outdated',
            description: 'Which remotely-installed plugins have a newer version available.',
            handler: fn (array $input): array => $this->inspection()->outdated($input),
            inputSchema: ['type' => 'object', 'properties' => []],
            scopes: ['plugins:read'],
            path: '/plugins/outdated',
        );

        $operations[] = new Operation(
            name: 'plugins.install',
            description: 'Install a plugin from a source coordinate, e.g. "acme/mail-plugin:^2.0".',
            handler: fn (array $input): array => $this->install($input),
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'source' => [
                        'type' => 'string',
                        'description' => 'Where the plugin comes from: "owner/repo", "owner/repo:^2.0", or a full URL.',
                    ],
                ],
                'required' => ['source'],
            ],
            mutating: true,
            // Installing runs somebody else's code on this host and can pull
            // composer packages with it. Whatever surface is driving gets to
            // put that in front of a person before it happens.
            requiresConfirmation: true,
            scopes: ['plugins:install'],
            path: '/plugins/install',
        );

        $operations[] = new Operation(
            name: 'plugins.update',
            description: 'Update an installed plugin to a newer version from the source it came from.',
            handler: fn (array $input): array => $this->update($input),
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Plugin name, e.g. "MailPlugin".'],
                    'version' => ['type' => 'string', 'description' => 'Target version constraint; omitted means the newest.'],
                ],
                'required' => ['name'],
            ],
            mutating: true,
            requiresConfirmation: true,
            scopes: ['plugins:install'],
            path: '/plugins/update',
        );

        $operations[] = new Operation(
            name: 'plugins.remove',
            description: 'Remove an installed plugin, optionally keeping the data it wrote.',
            handler: fn (array $input): array => $this->remove($input),
            inputSchema: [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'description' => 'Plugin name, e.g. "MailPlugin".'],
                    'keepData' => [
                        'type' => 'boolean',
                        'description' => 'Leave the plugin directory in place instead of deleting it.',
                        'default' => false,
                    ],
                ],
                'required' => ['name'],
            ],
            mutating: true,
            requiresConfirmation: true,
            scopes: ['plugins:write'],
            path: '/plugins/remove',
        );

        return $operations;
    }

    /**
     * The input schema shared by every operation that names one plugin.
     *
     * @return array<string, mixed>
     */
    private function nameSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string', 'description' => 'Plugin name, e.g. "MailPlugin".'],
            ],
            'required' => ['name'],
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{plugins: list<array<string, mixed>>}
     */
    private function list(array $input): array
    {
        $records = $this->known();
        if (($input['enabledOnly'] ?? false) === true) {
            $records = array_filter($records, static fn (PluginRecord $r): bool => $r->enabled);
        }

        return ['plugins' => array_map($this->describe(...), array_values($records))];
    }

    /**
     * Every plugin this host has, from both places it can have one: the store,
     * plus whatever it declares in code that the store has never heard of.
     *
     * Store records win on conflict — a record only exists because somebody
     * acted on that plugin, and that decision outranks the default.
     *
     * @return array<string, PluginRecord>
     */
    private function known(): array
    {
        $records = [];
        foreach ($this->registry->installed() as $record) {
            $records[$record->name] = $record;
        }

        foreach ($this->declared as $class) {
            $record = $this->recordFor($class);
            if ($record !== null && !isset($records[$record->name])) {
                $records[$record->name] = $record;
            }
        }

        return $records;
    }

    /**
     * The record a declared class would have: read from its `#[PluginMetadata]`,
     * enabled (declaring it is what turns it on), and sourced as `declared` so a
     * surface can tell it apart from something installed at runtime — one can be
     * removed, the other only by editing code.
     *
     * @param class-string $class
     */
    private function recordFor(string $class): ?PluginRecord
    {
        if (!class_exists($class)) {
            return null;
        }

        $attributes = (new \ReflectionClass($class))->getAttributes(PluginMetadata::class);
        if ($attributes === []) {
            return null;
        }

        $metadata = $attributes[0]->newInstance();

        return new PluginRecord(
            name: $metadata->name,
            version: $metadata->version,
            author: $metadata->author,
            site: $metadata->site,
            type: $metadata->type,
            installed: true,
            enabled: true,
            source: 'declared',
        );
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function show(array $input): array
    {
        return $this->describe($this->mustFind($this->name($input)));
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{name: string, enabled: bool}
     */
    private function setEnabled(array $input, bool $enabled, bool $overridden = false): array
    {
        $name = $this->name($input);
        $record = $this->mustFind($name);

        // SIN EVALUADOR NO SE APAGA. La ausencia de la infraestructura que demuestra que una mutación
        // es segura NO amplía la autoridad de esa mutación: la quita.
        //
        // Antes esto se permitía «perdiendo el aviso, no la capacidad», y medido resultó ser al revés:
        // un host sin gestor cableado —que es el que `milpa/framework` genera— podía apagar hasta
        // dejar el grafo abierto, y a partir de ahí `plugins.enable` tampoco corre porque necesita que
        // el host arranque. Se reprodujo con dos proveedores de una capacidad: apagar uno, apagar el
        // otro, y la app dejó de arrancar.
        //
        // Y no es hipotético que alguien elija mal: midiendo otra cosa, un agente ante una pregunta de
        // ANÁLISIS —«qué deja de funcionar si deshabilito X»— eligió apagar de verdad 3 de 8 veces,
        // teniendo `plugins.simulate` a la mano y descrita como lo que contesta eso sin hacerlo. Una
        // herramienta segura disponible no vuelve seguro al sistema mientras la destructiva siga
        // aceptando la misma intención sin una autoridad mayor.
        if (!$enabled && !$overridden && $this->safety === null) {
            throw new \RuntimeException(
                "MILPA_PLUGIN_SAFETY_UNAVAILABLE: no se puede deshabilitar {$name} porque este host no "
                . 'cablea un evaluador de seguridad de plugins, así que nadie puede comprobar si apagarlo '
                . 'dejaría el grafo sin cerrar. Para ver el efecto SIN cambiar nada: `plugins.simulate`. '
                . 'Para apagar de todos modos hace falta autoridad explícita: `plugins.disable-unsafe`, '
                . 'que exige confirmación y no se ofrece a un agente. Nada fue modificado.',
            );
        }

        // Apagar puede ser irreversible EN LA PRÁCTICA: si el perfil del host requiere una capacidad
        // que sólo este plugin provee, el resolver bloquea el siguiente arranque —y con razón, un
        // grafo abierto no debe arrancar— pero a partir de ahí `plugins.enable` tampoco corre, porque
        // necesita que el host arranque. Quien apagó se queda sin la herramienta con que encendería.
        //
        // Pasó de verdad: hubo que reencender un plugin escribiendo directo en la base de datos del
        // host. La negativa lleva el MOTIVO del resolver para que se sepa qué capacidad se quedaría
        // sin proveedor, en vez de sólo que no se puede.
        if (!$enabled && $this->safety !== null) {
            $motivo = $this->safety->blockingReasonWithout($name);
            if ($motivo !== null) {
                throw new \RuntimeException(
                    "Turning {$name} off would leave this host unable to boot: {$motivo} "
                    . 'Install or enable a provider for that capability first, or remove the requirement '
                    . "from the host profile. Nothing was changed.",
                );
            }
        }

        if ($this->registry->find($name) === null) {
            // A declared plugin with no record yet: switching it is the first
            // thing anyone ever did to it, so the record is created here rather
            // than at boot. That is what keeps an app that never manages
            // anything from growing a store it does not use.
            $this->registry->register($record->withEnabled($enabled));
        } else {
            $this->registry->setEnabled($name, $enabled);
        }

        $this->registry->invalidateActivationCache();

        // El resultado dice CÓMO se autorizó, no sólo qué pasó: un apagado evaluado y uno forzado son
        // hechos distintos, y un registro que los confunde no sirve para auditar después.
        // El bloque `safety` sólo al APAGAR: encender nunca se evalúa —agregar un proveedor no puede
        // quitarle uno a nadie— y ponerlo ahí sería ruido con forma de dato.
        return $enabled
            ? ['name' => $name, 'enabled' => true]
            : [
                'name' => $name,
                'enabled' => false,
                'safety' => ['evaluated' => $this->safety !== null, 'override' => $overridden],
            ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function install(array $input): array
    {
        $source = $input['source'] ?? null;
        if (!\is_string($source) || $source === '') {
            throw new \InvalidArgumentException('A source is required, e.g. "acme/mail-plugin:^2.0".');
        }

        $result = $this->installer?->require($source)
            ?? throw new \LogicException('No installer is wired on this host.');

        if (!$result->success) {
            throw new \RuntimeException((string) $result->error);
        }

        $this->registry->invalidateActivationCache();

        return [
            'name' => $result->pluginName,
            'version' => $result->version,
            'source' => $result->source,
            'composerPackagesInstalled' => $result->composerPackagesInstalled,
            'migrationsExecuted' => $result->migrationsExecuted,
            // A freshly installed plugin does NOT boot until somebody enables
            // it: installing is not consenting to run it.
            'enabled' => false,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function update(array $input): array
    {
        $name = $this->name($input);
        $this->mustFind($name);

        $version = $input['version'] ?? null;
        $result = $this->installer?->update($name, \is_string($version) && $version !== '' ? $version : null)
            ?? throw new \LogicException('No installer is wired on this host.');

        if (!$result->success) {
            throw new \RuntimeException((string) $result->error);
        }

        $this->registry->invalidateActivationCache();

        return [
            'name' => $result->pluginName,
            'version' => $result->version,
            'source' => $result->source,
            // update() reports "already at latest" as a success with a message;
            // it travels rather than being swallowed into a bare true.
            'note' => $result->error,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{name: string, removed: bool, dataKept: bool}
     */
    private function remove(array $input): array
    {
        $name = $this->name($input);
        $record = $this->mustFind($name);

        if ($record->source === 'declared') {
            throw new \RuntimeException(
                "Plugin {$name} is declared in this app's code. Remove its line from the plugin list; "
                . 'disable it here if what you want is for it to stop running.',
            );
        }

        $result = $this->installer?->remove($name, ($input['keepData'] ?? false) === true)
            ?? throw new \LogicException('No installer is wired on this host.');

        if (!$result->success) {
            throw new \RuntimeException((string) $result->error);
        }

        $this->registry->invalidateActivationCache();

        return ['name' => $name, 'removed' => true, 'dataKept' => $result->dataKept];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function name(array $input): string
    {
        $name = $input['name'] ?? null;
        if (!\is_string($name) || $name === '') {
            throw new \InvalidArgumentException('A plugin name is required.');
        }

        return $name;
    }

    private function mustFind(string $name): PluginRecord
    {
        return $this->known()[$name]
            ?? throw new \RuntimeException("Plugin {$name} is not installed.");
    }

    /**
     * The shape every surface renders — flat, already-serialisable, and
     * deliberately without the composer ledger, which is an implementation
     * detail of how a plugin got here rather than something to show.
     *
     * @return array<string, mixed>
     */
    private function describe(PluginRecord $record): array
    {
        return [
            'name' => $record->name,
            'version' => $record->installedVersion ?? $record->version,
            'author' => $record->author,
            'site' => $record->site,
            'type' => $record->type,
            'installed' => $record->installed,
            'enabled' => $record->enabled,
            'source' => $record->source ?? 'local',
            'installedAt' => $record->installedAt?->format(\DATE_ATOM),
        ];
    }
}
