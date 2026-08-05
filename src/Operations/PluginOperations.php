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
use Milpa\Command\Effect\Authority;
use Milpa\Command\Effect\EffectProfile;
use Milpa\Command\Effect\Externality;
use Milpa\Command\Effect\Mutation;
use Milpa\Command\Effect\Reversibility;
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
            // Lo declarado AHORA, por la misma razón que en `known()`: la inspección contesta
            // «¿existe este plugin?», y contestarlo desde la foto del arranque es lo que mandó a
            // trece sub-agentes a arreglar lo que ya estaba hecho ({@see self::declaredNow()}).
            $this->declaredNow(),
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
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    rollbackContract: 'nothing-to-roll-back',
                ),
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
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    rollbackContract: 'nothing-to-roll-back',
                ),
                description: 'Everything the registry knows about one plugin.',
                handler: fn (array $input): array => $this->show($input),
                inputSchema: $this->nameSchema(),
                scopes: ['plugins:read'],
                path: '/plugins/show',
            ),
            // `namedTarget: 'name'` es el contrato de intención de ADR-0044: el plugin que se prende
            // o se apaga tiene que venir NOMBRADO por quien lo pidió. Q-P19-K midió el costo de no
            // exigirlo — ante «quita el plugin viejo», tres corridas apagaron un plugin, tres otro,
            // y ningún hecho dice por qué. Un objetivo que la petición no nombra no se ejecuta: se
            // pregunta.
            new Operation(
                name: 'plugins.enable',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    // A REAL, TESTED INVERSE: `enable` and `disable` are literally the same method called with
                    // true and false. This is the only kind of evidence that earns `Guaranteed`, which is the
                    // only level that buys an operation less scrutiny.
                    Reversibility::Guaranteed,
                    Authority::WriteAsUser,
                    rollbackContract: 'plugins.disable',
                ),
                description: 'Turn a plugin on: it boots from the next request or command.',
                handler: fn (array $input): array => $this->setEnabled($input, true),
                inputSchema: $this->nameSchema(),
                mutating: true,
                scopes: ['plugins:write'],
                path: '/plugins/enable',
                namedTarget: 'name',
            ),
            new Operation(
                name: 'plugins.disable',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::WriteAsUser,
                    rollbackContract: 'plugins.enable',
                ),
                description: 'Turn a plugin off without removing it or its data.',
                handler: fn (array $input): array => $this->setEnabled($input, false),
                inputSchema: $this->nameSchema(),
                mutating: true,
                scopes: ['plugins:write'],
                path: '/plugins/disable',
                namedTarget: 'name',
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
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    // Its own description says it «may leave this host unable to boot». An app that will not boot
                    // cannot run the operation that would put it back, so the inverse is not available when it is
                    // needed — which is the definition of manual recovery, not of a guarantee.
                    Reversibility::ManualRecovery,
                    Authority::Privileged,
                ),
                description: 'Recovery only: turn a plugin off WITHOUT the safety evaluation. May leave this host unable to boot.',
                handler: fn (array $input): array => $this->setEnabled($input, false, overridden: true),
                inputSchema: $this->nameSchema(),
                mutating: true,
                requiresConfirmation: true,
                scopes: ['plugins:write'],
                surfaces: ['cli'],
                path: '/plugins/disable-unsafe',
                // THE INTENT CONTRACT, on the operation whose own text says it «may leave this host
                // unable to boot». It was missing, and the gate did not see it because this schema is
                // built by a method: it looked for a literal `'required' => ['…`, did not find one,
                // and concluded «no object to name» instead of «I could not look». Seven mutating
                // operations were invisible that way.
                //
                // «turn off the broken plugin» names none of them, and this is precisely the one that
                // does not allow guessing: turning off the wrong one without evaluating dependencies
                // is how you end up with an app that no longer starts so you can try again.
                namedTarget: 'name',
            ),
        ];

        // Las que MIRAN. Van después de las cuatro básicas y antes de las que salen a la red, que es
        // el orden en que una superficie debería presentarlas: ver, cambiar, y hasta el final traer
        // código de afuera.
        $operations[] = new Operation(
            name: 'plugins.deps',
            effects: new EffectProfile(
                Mutation::None,
                Externality::None,
                Reversibility::Guaranteed,
                Authority::Read,
                rollbackContract: 'nothing-to-roll-back',
            ),
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
            effects: new EffectProfile(
                Mutation::None,
                Externality::None,
                Reversibility::Guaranteed,
                Authority::Read,
                rollbackContract: 'nothing-to-roll-back',
            ),
            description: 'The capability graph as data: who provides what, who needs it, what is unsatisfied, and what breaks if you turn a plugin off.',
            handler: fn (array $input): array => $this->inspection()->architecture($input),
            inputSchema: ['type' => 'object', 'properties' => []],
            scopes: ['plugins:read'],
            path: '/plugins/architecture',
        );

        $operations[] = new Operation(
            name: 'plugins.simulate',
            effects: new EffectProfile(
                Mutation::None,
                Externality::None,
                Reversibility::Guaranteed,
                Authority::Read,
                rollbackContract: 'nothing-to-roll-back',
            ),
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
            // REGISTRAR ES DECLARAR QUÉ ARRANCA, y por eso lo autoriza la INTENCIÓN y no la capacidad.
            //
            // `config/plugins.php` se lee en un diff a propósito: qué corre en una app es una decisión
            // versionada. Lo que esa propiedad protege NO es que la teclee una persona —es que la
            // decisión quede escrita y legible—, y una operación gateada la deja igual de escrita: el
            // commit la muestra igual.
            //
            // Lo que decide si ESTA llamada procede es si quien pidió el trabajo pidió esto: con
            // `namedTarget: 'name'`, un plugin que la petición no nombra no se registra, se pregunta.
            // «Escribe el plugin Hola y verifica» contiene la consecuencia —verificar exige que
            // arranque— y pasa; «haz algo con los plugins» no la contiene, y escala al humano.
            //
            // El miedo que el docblock de la plantilla nombra —«un plugin que se instala solo desde la
            // red»— queda fuera por construcción: sólo se registran clases que YA existen en el árbol
            // de la app. Un paquete de `vendor/` no entra por aquí; ése es el camino de
            // `capabilities:enable`, que además pasa por el verificador.
            $operations[] = new Operation(
                name: 'plugins.register',
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    // It changes what the kernel BOOTS. Getting it wrong is discovered on the next start, when the
                    // operation that would undo it may no longer be reachable.
                    Reversibility::ManualRecovery,
                    Authority::Privileged,
                ),
                description: 'Declare a plugin that already exists in this app so the kernel boots it. '
                    . 'Only for plugin classes already scaffolded under the app tree — never a vendor package.',
                handler: fn (array $input): array => $this->register($input),
                inputSchema: $this->nameSchema(),
                mutating: true,
                scopes: ['plugins:write'],
                // La ruta se declara como en sus hermanas — para que el proyector no invente una— y
                // NO es una decisión de exposición: qué sale por HTTP lo nombra `config/http.php`,
                // que por default no nombra ninguna. Ésta es de las que no conviene publicar: hace
                // que una clase arranque en cada request, y eso pertenece a quien ya está en la
                // máquina, no a quien alcanza el puerto.
                path: '/plugins/register',
                namedTarget: 'name',
            );

            $operations[] = new Operation(
                name: 'plugins.verify',
                effects: new EffectProfile(
                    Mutation::None,
                    Externality::None,
                    Reversibility::Guaranteed,
                    Authority::Read,
                    rollbackContract: 'nothing-to-roll-back',
                ),
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
                effects: new EffectProfile(
                    Mutation::Persistent,
                    Externality::None,
                    // Rewrites `milpa.lock` from the current registry. The previous lock is not kept anywhere by
                    // this operation — VCS is the recovery path, and VCS is a human with a terminal.
                    Reversibility::ManualRecovery,
                    Authority::WriteAsUser,
                ),
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
            effects: new EffectProfile(
                Mutation::None,
                // READS, AND STILL REACHES A THIRD PARTY: it asks a remote registry what is newer. This is
                // exactly why externality is its own dimension and not a shade of mutation — `mutating: false`
                // would have said «harmless» about an operation that talks to the internet.
                Externality::ThirdParty,
                Reversibility::Guaranteed,
                Authority::Read,
                rollbackContract: 'nothing-to-roll-back',
            ),
            description: 'Which remotely-installed plugins have a newer version available.',
            handler: fn (array $input): array => $this->inspection()->outdated($input),
            inputSchema: ['type' => 'object', 'properties' => []],
            scopes: ['plugins:read'],
            path: '/plugins/outdated',
        );

        $operations[] = new Operation(
            name: 'plugins.install',
            effects: new EffectProfile(
                Mutation::Persistent,
                // IT BRINGS CODE FROM SOMEBODY ELSE INTO THIS PROCESS — and that is exactly the route ADR-0045
                // closes. What arrives runs with the app's own authority, sharing its memory, its container and
                // its disk. Measured 2026-08-05: 13 of 34 packages in this family can reach network or spawn a
                // process without going through any declared operation; a third-party one has the same power and
                // none of the reasons to be trusted.
                Externality::ThirdParty,
                // `plugins.remove` exists but it is not a tested inverse of this: whatever the plugin's install
                // hook already did to the app is not undone by deleting its directory.
                Reversibility::ManualRecovery,
                // The highest authority in the whole catalogue: it decides what code this app runs.
                Authority::Privileged,
                escalatesOn: ['source'],
            ),
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
            // EL OBJETIVO LO NOMBRA EL HUMANO (ADR-0044), y esto lo puso una MEDICIÓN.
            //
            // Q-P20-J (2026-08-04) midió que un agente al que se le pide «arregla la advertencia»
            // instala ocho de ocho veces un paquete que nadie nombró — por la puerta hermana que
            // no tenía contrato. La firma NO lo detiene: la firma pregunta «¿autorizas esto?» y el
            // humano sí iba a autorizar «arregla»; el contrato pregunta «¿es esto lo que pediste?»,
            // que es la que faltaba.
            namedTarget: 'source',
        );

        $operations[] = new Operation(
            name: 'plugins.update',
            effects: new EffectProfile(
                Mutation::Persistent,
                // A NEW VERSION INHERITS NOTHING (GOV-11): whatever was attested was a digest, and updating
                // replaces it. «Same name, same source» is not «same code».
                Externality::ThirdParty,
                Reversibility::ManualRecovery,
                Authority::Privileged,
                escalatesOn: ['name'],
            ),
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
            // Mismo contrato que `plugins.install`, por lo mismo (Q-P20-J).
            namedTarget: 'name',
        );

        $operations[] = new Operation(
            name: 'plugins.remove',
            effects: new EffectProfile(
                Mutation::Persistent,
                Externality::None,
                // Deleting the directory does not undo what the plugin already did to this app: rows it wrote,
                // files it created, config it changed. Recovery is a human reading what it touched.
                Reversibility::ManualRecovery,
                Authority::Privileged,
                escalatesOn: ['name'],
            ),
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
            // Mismo contrato, y aquí importa MÁS: quitar un plugin es más difícil de deshacer que
            // instalar uno, y hasta hoy `capabilities:enable` exigía objetivo nombrado y esto no.
            // Una asimetría así se lee como descuido porque lo era (Q-P20-J).
            namedTarget: 'name',
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
    /**
     * Lo declarado AHORA, no lo que se declaró al arrancar.
     *
     * ── POR QUÉ SE VUELVE A LEER ────────────────────────────────────────────────────────────────
     *
     * `$declared` llega en el constructor, o sea en el arranque. Mientras nada pudiera cambiar esa
     * lista dentro de un proceso, la foto y el estado vigente coincidían siempre y la diferencia era
     * invisible. Desde que existe `plugins.register` dejó de serlo — y el costo quedó medido:
     *
     * De 32 corridas delegadas (Q-P19-W), 13 se dispararon hasta agotar su techo y **12 de esas 13**
     * llamaron `plugins.verify`, que falló catorce veces con «no existe el plugin» **sobre el plugin
     * que el sub-agente acababa de registrar con éxito en el mismo turno**. Un turno del agente es UN
     * proceso: registraba, preguntaba, y el sistema le contestaba que no existía lo que él acababa de
     * crear. El bucle no era del modelo — era la respuesta correcta a una contradicción.
     *
     * Es la misma clase que Q-P20-B midió en el catálogo del agente: una foto traída una vez no
     * redirige conducta; hay que reproyectar. La doctrina ya estaba escrita; faltaba aplicarla aquí.
     *
     * ── LA UNIÓN, Y NO EL REEMPLAZO ─────────────────────────────────────────────────────────────
     *
     * Lo que arrancó sigue contando aunque alguien lo haya borrado del archivo a media corrida:
     * quitarlo de la lista no lo apaga hasta el siguiente arranque, y decir que ya no está mentiría
     * sobre lo que está corriendo AHORA. Se suman las dos, que es lo único honesto mientras una sola
     * respuesta tenga que cubrir dos preguntas —«¿está declarado?» y «¿está corriendo?»—; separarlas
     * es la deuda que el tablero conserva como `plugin-list-is-reprojected`.
     *
     * @return list<class-string>
     */
    private function declaredNow(): array
    {
        if ($this->root === null) {
            return $this->declared;
        }

        $archivo = $this->root . '/config/plugins.php';
        if (!is_file($archivo)) {
            return $this->declared;
        }

        // Un archivo de configuración cuyo único trabajo es devolver un arreglo. Si devuelve otra
        // cosa —porque su dueño le puso algo más— se conserva la foto: no saber leerlo no autoriza a
        // inventar una lista.
        $ahora = @require $archivo;
        if (!\is_array($ahora)) {
            return $this->declared;
        }

        $union = $this->declared;
        foreach ($ahora as $clase) {
            if (\is_string($clase) && !\in_array($clase, $union, true)) {
                $union[] = $clase;
            }
        }

        return $union;
    }

    /**
     * Todo plugin que este host conoce, por nombre: lo que su registro guarda y lo que declara.
     *
     * @return array<string, PluginRecord>
     */
    private function known(): array
    {
        $records = [];
        foreach ($this->registry->installed() as $record) {
            $records[$record->name] = $record;
        }

        foreach ($this->declaredNow() as $class) {
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

    /**
     * Agrega un plugin del árbol de esta app a `config/plugins.php`.
     *
     * ── LOS TRES CERROJOS, Y NINGUNO ES DECORATIVO ──────────────────────────────────────────────
     *
     * 1. **Sólo lo que ya existe en el árbol.** Se resuelve la clase contra `src/Plugins/<N>/<N>.php`
     *    y si el archivo no está, no se registra. Un nombre que el modelo invente no llega a la lista.
     * 2. **Nunca `vendor/`.** Un paquete de la red no entra por aquí; para eso está
     *    `capabilities:enable`, que además pasa por el verificador.
     * 3. **La forma del archivo se comprueba antes de escribir.** Si no se reconoce —porque su dueño
     *    lo reescribió— NO se adivina: se devuelve la línea exacta para agregarla a mano. Editar a
     *    ciegas el archivo que decide qué arranca es peor que no editarlo.
     *
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function register(array $input): array
    {
        $name = $this->name($input);

        if ($this->root === null) {
            return ['ok' => false, 'error' => 'este host no declara su raíz, así que no se puede saber qué archivo editar'];
        }

        // El nombre corto basta: la convención del andamio es `src/Plugins/<N>/<N>.php` con
        // `App\Plugins\<N>\<N>`. Un FQCN se acepta y se reduce a su última parte.
        $corto = str_contains($name, '\\') ? (string) substr(strrchr($name, '\\') ?: '', 1) : $name;
        if ($corto === '' || preg_match('/^[A-Z][A-Za-z0-9]*$/', $corto) !== 1) {
            return ['ok' => false, 'error' => "«{$name}» no parece un nombre de clase de plugin"];
        }

        $archivoClase = $this->root . '/src/Plugins/' . $corto . '/' . $corto . '.php';
        if (!is_file($archivoClase)) {
            return [
                'ok' => false,
                'error' => "no existe {$archivoClase}: sólo se registran plugins que ya están en el árbol de esta app",
                'hint' => 'ándalo primero con `make plugin ' . $corto . ' ' . $corto . '`',
            ];
        }

        $fqcn = 'App\\Plugins\\' . $corto . '\\' . $corto;
        $lista = $this->root . '/config/plugins.php';
        $contenido = is_file($lista) ? (string) file_get_contents($lista) : '';
        if ($contenido === '') {
            return ['ok' => false, 'error' => "no se pudo leer {$lista}"];
        }

        if (str_contains($contenido, $corto . '::class')) {
            // YA ESTABA NO ES UN ERROR, por lo mismo que en `capabilities`: quien pide dos veces
            // recibe que ya está, no que falló — un fallo lo manda a buscar otro camino.
            return ['ok' => true, 'plugin' => $corto, 'hint' => 'ya estaba declarado — nada que hacer'];
        }

        // La forma que se reconoce: un `return [` y un `];` al final. Si el archivo no la tiene, se
        // dice y se entrega la línea, en vez de inventar un lugar donde meterla.
        if (preg_match('/\n\];\s*$/', $contenido) !== 1 || !str_contains($contenido, 'return [')) {
            return [
                'ok' => false,
                'error' => "no reconozco la forma de {$lista}, así que no lo edito a ciegas",
                'add_by_hand' => ['use ' . $fqcn . ';', '    ' . $corto . '::class,'],
            ];
        }

        $conUse = preg_replace(
            '/^(declare\(strict_types=1\);\n)/m',
            "$1\nuse " . $fqcn . ";\n",
            $contenido,
            1,
        ) ?? $contenido;
        // Si no hubo dónde poner el `use`, el FQCN va completo en la lista: sigue siendo válido y no
        // deja el archivo a medias.
        $entrada = $conUse === $contenido ? '    \\' . $fqcn . '::class,' : '    ' . $corto . '::class,';
        $nuevo = (string) preg_replace('/\n\];\s*$/', "\n" . $entrada . "\n];\n", $conUse, 1);

        if ($nuevo === $conUse || file_put_contents($lista, $nuevo) === false) {
            return ['ok' => false, 'error' => "no se pudo escribir {$lista}", 'add_by_hand' => [$entrada]];
        }

        return [
            'ok' => true,
            'plugin' => $corto,
            'declared_in' => 'config/plugins.php',
            // QUE HAYA QUEDADO ESCRITO NO ES QUE ARRANQUE, y decirlo es la diferencia entre un
            // resultado y una promesa: el kernel lo bota en la siguiente corrida, no en ésta.
            'hint' => 'arranca desde el siguiente comando o request — corre `plugins.list` para verlo',
        ];
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
