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
     */
    public function __construct(
        private PluginRegistryInterface $registry,
        private ?PluginInstallerInterface $installer = null,
        private array $declared = [],
        private ?ActivationSafetyInterface $safety = null,
    ) {
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
        ];

        if ($this->installer === null) {
            return $operations;
        }

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
    private function setEnabled(array $input, bool $enabled): array
    {
        $name = $this->name($input);
        $record = $this->mustFind($name);

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

        return ['name' => $name, 'enabled' => $enabled];
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
