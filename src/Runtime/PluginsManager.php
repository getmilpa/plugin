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

use Milpa\Attributes\PluginMetadata;
use Milpa\Events\CapabilityResolvedEvent;
use Milpa\Events\InterceptionSlot;
use Milpa\Events\KernelBootedEvent;
use Milpa\Events\PluginBootedEvent;
use Milpa\Events\PluginBootingEvent;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Event\MilpaEventDispatcherInterface;
use Milpa\Interfaces\Plugin\PluginInterface;
use Milpa\Interfaces\Plugin\PluginsManagerInterface;
use Milpa\Interfaces\Tooling\ToolRegistryInterface;
use Milpa\Plugin\Contracts\ActivationSafetyInterface;
use Milpa\Plugin\Contracts\PluginRegistryInterface;
use Milpa\Resolver\Engine\GraphResolver;
use Milpa\Resolver\Ingest\AttributeLoader;
use Milpa\Resolver\Input\ResolutionInput;
use Milpa\Resolver\Manifest\HostProfile;
use Milpa\Resolver\Report\ResolutionReport;
use Milpa\Resolver\Report\ResolutionStatus;
use Milpa\ValueObjects\Capability\CapabilityRequirement;
use Psr\Log\LoggerInterface;

/**
 * Discovers, gates, orders, and boots the plugins that make up a running
 * Milpa application. The direct port of the host's legacy manager with every
 * host coupling replaced: globals became {@see ManagerConfig}, the enabled
 * set reads through {@see PluginRegistryInterface}, the tool registry is the
 * core contract, and the plugin list is instance state.
 */
final class PluginsManager implements ActivationSafetyInterface, PluginsManagerInterface
{
    private DIContainerInterface $container;

    private LoggerInterface $logger;

    /** @var array<string> */
    private array $enabledPlugins = [];

    /** @var array<string, PluginInterface> */
    private array $pluginInstances = [];

    /** @var array<string> */
    private array $pluginsPaths = [];

    /** @var list<array<string, mixed>> Scanned plugin metadata, resequenced to boot order. */
    private array $plugins = [];

    public function __construct(
        DIContainerInterface $container,
        private readonly PluginRegistryInterface $registry,
        private readonly ManagerConfig $config,
    ) {
        $this->container = $container;
        $this->logger = $this->container->get(LoggerInterface::class);
    }

    /**
     * Plugin metadata for every scanned plugin, resequenced to boot order.
     *
     * @return list<array<string, mixed>>
     */
    public function getPluginsMetadata(): array
    {
        return $this->plugins;
    }

    /**
     * El motivo por el que apagar `$pluginName` dejaría el grafo bloqueado, o `null` si no lo haría.
     *
     * Resuelve el MISMO grafo que el arranque, con ese plugin fuera. No es una aproximación: usa el
     * perfil del host y el resolver que van a decidir de verdad, así que una respuesta afirmativa
     * aquí es la misma que se daría en el siguiente arranque.
     *
     * Sin perfil legible contesta `null`, o sea «adelante»: es exactamente lo que hace el resto de
     * esta clase cuando no lo encuentra —{@see self::cachedGraphIsBootable()} lo dice con todas sus
     * letras— porque inventar un perfil bloquearía apagados que hoy funcionan.
     *
     * Un plugin que no está entre los activos tampoco bloquea nada: apagar lo que ya está apagado no
     * cambia el grafo.
     */
    public function blockingReasonWithout(string $pluginName): ?string
    {
        // Sin perfil de host se usa el PERMISIVO, no se abandona la comprobación.
        //
        // Devolver `null` aquí —que es lo que hacía— convertía la guarda en decorativa para toda app
        // que no declare `milpa.json`, y la plantilla que `milpa/framework` genera no declara ninguno:
        // o sea, para TODAS. Reproducido con dos proveedores de una capacidad, apagando uno y luego
        // el otro: el segundo apagado pasó y la app dejó de arrancar — exactamente lo que el docblock
        // de este método dice que existe para impedir, y que ya había pasado de verdad una vez.
        //
        // El perfil permisivo no es «sin comprobación»: no impone exigencias del host, pero SÍ exige
        // que los `requires` de los plugins cierren, que es justo el caso. Y es lo que el camino de
        // ARRANQUE de esta misma clase ya hacía —`loadHostProfile() ?? permissiveHostProfile()`— así
        // que esto no inventa una política: quita una divergencia entre dos caminos que contestaban
        // la misma pregunta de forma distinta.
        $hostProfile = $this->loadHostProfile() ?? $this->permissiveHostProfile();

        $restantes = array_values(array_filter(
            $this->plugins,
            static fn (array $p): bool => ($p['name'] ?? null) !== $pluginName,
        ));

        if (\count($restantes) === \count($this->plugins)) {
            return null;
        }

        try {
            $report = $this->resolveGraph($restantes, $hostProfile);
        } catch (\Throwable $e) {
            // No poder resolver NO es lo mismo que resolver mal, y aquí la diferencia importa: negar
            // un apagado porque el instrumento falló dejaría a alguien sin poder apagar nada. Se
            // avisa y se deja pasar, que es lo que esta clase ya hace con un grafo cacheado ilegible.
            $this->logger->warning(
                '[Plugins] No se pudo comprobar si apagar ' . $pluginName . ' bloquearía el arranque: '
                . $e->getMessage() . '. Se permite el apagado.'
            );

            return null;
        }

        if ($report->status !== ResolutionStatus::Blocked) {
            return null;
        }

        return $report->firstLearnableLine()
            ?? 'el grafo de arquitectura quedaría bloqueado; el resolver no reportó un error legible.';
    }

    /**
     * El motivo por el que AGREGAR `$newPluginClass` dejaría el grafo bloqueado, o `null` si cerraría.
     *
     * La otra mitad de {@see self::blockingReasonWithout()}: el invariante es uno —el grafo nunca se deja
     * abierto por una mutación (greenhouse decisions/0178). Un plugin sin `#[PluginMetadata]` no declara
     * `requires`: agregarlo no puede abrir el grafo, así que no hay nada que bloquear. Si el resolver mismo
     * TRUENA, se falla CERRADO al agregar (a diferencia de quitar, que tiene vía de recuperación): un
     * registro que podría abrir el grafo y no se pudo verificar no se autoriza.
     */
    public function blockingReasonWith(string $newPluginClass): ?string
    {
        try {
            $meta = $this->getMetadataFromAttributes($newPluginClass);
        } catch (\Throwable) {
            // Sin metadata no hay `requires`: agregarlo es seguro. No es «no se pudo», es «nada que comprobar».
            return null;
        }

        $hostProfile = $this->loadHostProfile() ?? $this->permissiveHostProfile();
        $conNuevo = $this->plugins;
        $conNuevo[] = $meta;

        try {
            $report = $this->resolveGraph($conNuevo, $hostProfile);
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[Plugins] No se pudo comprobar si registrar ' . $newPluginClass . ' bloquearía el arranque: '
                . $e->getMessage() . '. Se niega el registro por precaución.'
            );

            return 'no se pudo comprobar si el grafo cerraría con ' . $newPluginClass
                . ' (' . $e->getMessage() . '), así que no se autoriza el registro.';
        }

        if ($report->status !== ResolutionStatus::Blocked) {
            return null;
        }

        return $report->firstLearnableLine()
            ?? 'el grafo de arquitectura quedaría bloqueado; el resolver no reportó un error legible.';
    }

    /**
     * Returns all booted plugin instances, keyed by plugin name.
     *
     * @return array<string, PluginInterface>
     */
    public function getPlugins(): array
    {
        return $this->pluginInstances;
    }

    /**
     * Returns a single booted plugin instance by name, or null if no
     * such plugin has been booted.
     */
    public function getPlugin(string $name): ?PluginInterface
    {
        return $this->pluginInstances[$name] ?? null;
    }

    /**
     * Whether the given plugin name is currently enabled.
     */
    public function isEnabled(string $name): bool
    {
        return in_array($name, $this->enabledPlugins, true);
    }

    /**
     * Get prompt sections from all plugins that implement ToolProviderInterface.
     * This allows dynamically building the AI system prompt based on registered tools.
     *
     * @return array<string> All prompt sections from tool providers
     */
    public function getToolProviderPromptSections(): array
    {
        $allSections = [];

        foreach ($this->plugins as $pluginMetadata) {
            $className = $pluginMetadata['class'] ?? null;
            if (!$className || !$this->container->has($className)) {
                continue;
            }

            $plugin = $this->container->get($className);

            if ($plugin instanceof \Milpa\Interfaces\Tooling\ToolProviderInterface) {
                $sections = $plugin->getPromptSections();
                if (!empty($sections)) {
                    $allSections = array_merge($allSections, $sections);
                    $allSections[] = ''; // Add blank line between plugins
                }
            }
        }

        return $allSections;
    }

    /**
     * Registers a directory where plugins may be found.
     *
     * @param string $path Physical path to the plugins directory.
     *
     * @return void
     */
    public function addPluginPath(string $path): void
    {
        if (!in_array($path, $this->pluginsPaths)) {
            $this->pluginsPaths[] = $path;
        } else {
            $this->logger->debug("PluginSystem path already exists: $path");
        }
    }

    /**
     * Removes a previously registered path from the plugins directory list.
     *
     * @param string $path Physical path to the plugins directory to remove.
     *
     * @return void
     */
    public function removePluginPath(string $path): void
    {
        if (in_array($path, $this->pluginsPaths)) {
            unset($this->pluginsPaths[array_search($path, $this->pluginsPaths)]);
        }
    }

    /**
     * Returns every path currently registered as a possible plugin location.
     *
     * @return array<string>
     */
    public function getPluginsPaths(): array
    {
        return $this->pluginsPaths;
    }

    /**
     * Loads and registers plugins from every registered plugin path.
     * Implements caching to avoid a disk scan on every request.
     *
     * The fresh path (cache miss/stale) runs ONE milpa/resolver resolution that both GATES the
     * graph (a `blocked` verdict throws, with the report's learnable first line as the message)
     * and ORDERS the boot ($this->plugins is re-sequenced by the report's loadOrder[]); the
     * cache then persists that order, so the cache-hit path boots it without re-sorting.
     */
    public function loadPlugins(): void
    {
        $enabledCacheFile = $this->config->cacheDir . DIRECTORY_SEPARATOR . 'enabled_plugins.php';
        if (file_exists($enabledCacheFile)) {
            $this->enabledPlugins = require $enabledCacheFile;
        } else {
            $this->enabledPlugins = $this->rebuildEnabledPluginsCache($enabledCacheFile);
        }

        $devMode = $this->config->devMode;
        $cacheFile = $this->config->cacheDir . DIRECTORY_SEPARATOR . 'plugins.php';

        if (!$devMode && file_exists($cacheFile)) {
            if ($this->loadFromCache($cacheFile)) {
                // kernel.booted (POST): plugin boot sequence complete for this request/process.
                $this->emitKernelBooted();
                return;
            }
            // The cached graph no longer resolves against the current host profile (the §5
            // blind-spot): fall through to the full scan -> validate -> boot path below, which
            // rewrites the cache after a successful boot (self-healing, never a hard crash
            // from a stale cache).
        }

        // Scan plugins (collect metadata but don't boot yet)
        if ($this->pluginsPaths) {
            foreach ($this->pluginsPaths as $pluginPath) {
                $this->scanPluginsPath($pluginPath);
            }

            // ONE milpa/resolver resolution gates AND orders the boot: the same report that
            // decides whether this graph may boot (replacing the legacy
            // ContractResolver::validate(), whose missing-require RuntimeException becomes the
            // report's `blocked` verdict) also dictates the sequence it boots in (replacing
            // ContractResolver::getLoadOrder() — the report's loadOrder[] runs the same Kahn
            // pass, so the order is identical). An EMPTY enabled set is skipped whole: there is
            // nothing to gate and nothing to order, and resolving it against the host profile's
            // own requiredCapabilities would turn a boot that works today into a crash —
            // `coa:inspect architecture` already teaches the full-disk picture.
            if ($this->plugins !== []) {
                $hostProfile = $this->loadHostProfile() ?? $this->permissiveHostProfile();
                try {
                    $report = $this->resolveGraph($this->plugins, $hostProfile);
                } catch (\Throwable $e) {
                    $this->logger->error('[Plugins] Architecture resolution failed: ' . $e->getMessage());
                    throw $e instanceof \RuntimeException
                        ? $e
                        : new \RuntimeException('Architecture resolution failed: ' . $e->getMessage(), 0, $e);
                }

                if ($report->status === ResolutionStatus::Blocked) {
                    $message = $report->firstLearnableLine()
                        ?? 'the architecture graph is blocked; the resolver reported no learnable error.';
                    $this->logger->error('[Plugins] Architecture graph is blocked — ' . $message);
                    throw new \RuntimeException($message);
                }

                $this->plugins = $this->orderFromReport($report, $this->plugins);
            }

            // capability.resolved (POST): the dependency-ordered plugin graph is final —
            // fires before any plugin boots.
            $this->emitCapabilityResolved($this->plugins);

            // Now boot plugins in proper order
            foreach ($this->plugins as $plugin) {
                $this->registerAndBoot($plugin['class'], $plugin['name'], $plugin);
            }

            // Generate cache if not in dev mode
            if (!$devMode) {
                $this->writeCache($cacheFile);
            }
        } else {
            $this->logger->warning("No plugins paths found");
        }

        // kernel.booted (POST): plugin boot sequence complete for this request/process.
        $this->emitKernelBooted();
    }

    /**
     * Boot the plugin graph a previous successful resolution left in the plugins cache — but only
     * after re-validating that graph against the CURRENT host profile (the §5 blind-spot: the
     * cache-hit path used to boot whatever the cache said, unchecked). A cached graph the resolver
     * reports as `blocked` is rejected (returns false) so the caller falls back to the full
     * scan + validate + boot path, which rewrites the cache after a successful boot.
     *
     * @return bool True when the cached graph re-validated and booted; false when the cache is
     *              stale/blocked and the full load path must run instead (self-healing).
     */
    private function loadFromCache(string $cacheFile): bool
    {
        $cachedPlugins = require $cacheFile;
        $toBoot = [];
        foreach ($cachedPlugins as $metadata) {
            $className = $metadata['class'] ?? null;
            if ($className && class_exists($className)) {
                $toBoot[] = $metadata;
            }
        }

        if (!$this->cachedGraphIsBootable($toBoot)) {
            return false;
        }

        foreach ($toBoot as $metadata) {
            $this->plugins[] = $metadata;
        }

        // capability.resolved (POST): graph reused from a previous successful resolution
        // (the plugins cache), finalized before any plugin boots this run.
        $this->emitCapabilityResolved($this->plugins);

        foreach ($toBoot as $metadata) {
            $this->registerAndBoot($metadata['class'], $metadata['name'] ?? $metadata['class'], $metadata);
        }

        return true;
    }

    /**
     * Re-validate the cached plugin graph through milpa/resolver against the host profile of the
     * root `milpa.json` — the same load path `coa:inspect architecture` uses. Every cached metadata
     * array is rebuilt into a {@see PluginMetadata} record and ingested via
     * {@see AttributeLoader::fromMetadata()}; each plugin's `requires` becomes a capability
     * requirement the graph must close. Only a `blocked` verdict rejects the cache (logged with the
     * report's first learnable error: code + message + why + fix + learn link, via
     * {@see ResolutionReport::firstLearnableLine()}); `valid`, `bootable_with_warnings` and
     * `legacy_compatible` all boot. A cache too malformed to even resolve is as unbootable as a
     * blocked one, so it is rejected too — the fallback path never crashes, it rescans.
     *
     * @param array<int, array<string, mixed>> $cachedPlugins Cached metadata arrays
     *                                                        (name/version/author/site/type/provides/requires/suggests/class).
     */
    private function cachedGraphIsBootable(array $cachedPlugins): bool
    {
        $hostProfile = $this->loadHostProfile();
        if ($hostProfile === null) {
            // No readable hostProfile: behave exactly as before (no resolver gate). Inventing a
            // default profile here could block a boot that works today.
            return true;
        }

        try {
            $report = $this->resolveGraph($cachedPlugins, $hostProfile);
        } catch (\Throwable $e) {
            $this->logger->warning(
                '[Plugins] Cached plugin graph could not be re-validated: ' . $e->getMessage()
                . '. Falling back to a full plugin scan; the cache rebuilds after a successful boot.'
            );
            return false;
        }

        if ($report->status === ResolutionStatus::Blocked) {
            $message = $report->firstLearnableLine()
                ?? 'the architecture graph is blocked; the resolver reported no learnable error.';
            $this->logger->warning(
                '[Plugins] Cached plugin graph is blocked — ' . $message
                . ' Falling back to a full plugin scan; the cache rebuilds after a successful boot.'
            );
            return false;
        }

        return true;
    }

    /**
     * Resolve a set of plugin metadata arrays through milpa/resolver against a host profile —
     * the ONE resolution shape both boot paths share: the fresh path (gate + order, see
     * {@see loadPlugins()}) and the cache-hit re-validation ({@see cachedGraphIsBootable()}).
     * Every metadata array is rebuilt into a {@see PluginMetadata} record and ingested via
     * {@see AttributeLoader::fromMetadata()}; each plugin's `requires` becomes a capability
     * requirement the graph must close — in BOTH real shapes, a legacy bare FQCN string or a
     * canonical requirement record, via {@see CapabilityRequirement::parse()}.
     *
     * @param array<int, array<string, mixed>> $metadataArrays Plugin metadata arrays
     *                                                         (name/version/author/site/type/provides/requires/suggests/class).
     *
     * @throws \Milpa\Resolver\Exceptions\InvalidManifestException A metadata array's name/version
     *                                                             do not form a valid manifest.
     */
    private function resolveGraph(array $metadataArrays, HostProfile $hostProfile): ResolutionReport
    {
        $loader = new AttributeLoader();
        $manifests = [];
        $requirements = [];
        foreach ($metadataArrays as $record) {
            $metadata = new PluginMetadata(
                version: is_string($record['version'] ?? null) ? $record['version'] : '',
                author: is_string($record['author'] ?? null) ? $record['author'] : '',
                site: is_string($record['site'] ?? null) ? $record['site'] : '',
                name: is_string($record['name'] ?? null) ? $record['name'] : '',
                type: is_string($record['type'] ?? null) ? $record['type'] : '',
                provides: is_array($record['provides'] ?? null) ? array_values($record['provides']) : [],
                requires: is_array($record['requires'] ?? null) ? array_values($record['requires']) : [],
                suggests: is_array($record['suggests'] ?? null) ? array_values($record['suggests']) : [],
            );
            $manifests[] = $loader->fromMetadata($metadata);
            foreach ($metadata->requires as $entry) {
                // parse() dispatches both real shapes — a legacy bare FQCN string
                // (fromInterface, exactly as before) or a canonical requirement record
                // (fromArray). fromMetadata() above already taught the malformed-entry
                // lesson, so $entry is string|array here (the T1-M2 seam: a rich
                // requires record must not raw-TypeError the boot).
                $requirements[] = CapabilityRequirement::parse($entry);
            }
        }

        return (new GraphResolver())->resolve(new ResolutionInput(
            hostProfile: $hostProfile,
            versionManifests: $manifests,
            contractManifests: [],
            // WHAT THE INSTALLED DISTRIBUTIONS PROVIDE, at boot (P17.4).
            //
            // This was `[]`, so the boot graph only ever saw plugins. A host that declared a
            // requirement on a distribution capability —`tool.registry`, say— got MILPA_CAPABILITY_
            // MISSING **even with the package installed**, because nothing ever told the graph the
            // package was there. That made the whole capability→package recommendation unusable in
            // the one place it matters: a host could not declare what it needs from a distribution
            // without breaking its own boot.
            //
            // The vendor root is derived from the host manifest's directory: they are the same app,
            // and asking the caller for a second path is asking two questions with one answer.
            capabilityProvisions: $this->installedProvisions(),
            capabilityRequirements: $requirements,
        ));
    }

    /**
     * The provisions of the installed distributions, or none when this host has no manifest to
     * locate them from.
     *
     * @return list<\Milpa\ValueObjects\Capability\CapabilityProvision>
     */
    private function installedProvisions(string $cargador = 'Milpa\\Resolver\\Ingest\\InstalledCapabilityLoader'): array
    {
        $manifiesto = $this->config->hostManifestPath;
        if (!is_string($manifiesto) || $manifiesto === '') {
            return [];
        }

        // La clase puede no estar: este paquete declara `milpa/resolver: ^0.5.2 || ^0.6` y sólo existe
        // en 0.6. Llamarla sin comprobar afirma una versión que el pin no exige — y con 0.5 instalado
        // reventaría en el arranque, que es donde menos se puede.
        if (!class_exists($cargador)) {
            return [];
        }

        return $cargador::fromVendor(\dirname($manifiesto) . '/vendor');
    }

    /**
     * Project the report's `loadOrder[]` — the boot sequence the SAME resolution that gated the
     * graph computed (provides -> requires, ties in scan order) — onto the scanned metadata
     * arrays, so the boot loop and the cache consume the exact arrays they always consumed, now
     * sequenced by the report.
     *
     * Defensive by contract, never silent: both sides derive from the same scanned metadata, so
     * every `loadOrder` name maps to a record — and every record appears in `loadOrder`, because
     * the only entries the resolver ever excludes are dependency-cycle members and a cycle means
     * `blocked`, already thrown at the gate. A mismatch in either direction means the boot would
     * silently skip or drop a plugin, so this throws instead.
     *
     * @param array<int, array<string, mixed>> $plugins Scanned metadata arrays, keyed-by-name unique
     *                                                  (see scanPlugin()'s duplicate check).
     *
     * @return array<int, array<string, mixed>> The same arrays, in the report's boot order.
     */
    private function orderFromReport(ResolutionReport $report, array $plugins): array
    {
        $byName = [];
        foreach ($plugins as $plugin) {
            $byName[$plugin['name']] = $plugin;
        }

        $ordered = [];
        foreach ($report->loadOrder as $entry) {
            $name = $entry['name'] ?? null;
            if (!is_string($name) || !isset($byName[$name])) {
                throw new \RuntimeException(sprintf(
                    "The resolver's loadOrder names '%s', but no scanned plugin carries that name — the resolver and the host disagreed about the inputs.",
                    is_string($name) ? $name : get_debug_type($name),
                ));
            }
            $ordered[] = $byName[$name];
        }

        if (count($ordered) !== count($plugins)) {
            throw new \RuntimeException(sprintf(
                "The resolver's loadOrder sequences %d of the %d scanned plugins; a dependency cycle would have blocked the gate, so a plugin was dropped without a diagnosis — the resolver and the host disagreed about the inputs.",
                count($ordered),
                count($plugins),
            ));
        }

        return $ordered;
    }

    /**
     * The DELIBERATELY PERMISSIVE profile the fresh path resolves against when the root
     * `milpa.json` offers no readable hostProfile — the same default `milpa/runtime`'s Kernel
     * applies: no host-level demands, every legacy path allowed. With it, the gate reduces to
     * exactly what the legacy `ContractResolver::validate()` enforced (plugin `requires` closed,
     * no cycles), so a graph that booted before the swap still boots. The cache-hit gate keeps
     * its "no profile, no gate" contract instead ({@see cachedGraphIsBootable()}); the fresh
     * path cannot skip the resolution — the same resolve is what ORDERS the boot.
     */
    private function permissiveHostProfile(): HostProfile
    {
        return new HostProfile(name: 'host', version: '0.0.0', allowedLegacyContracts: ['*']);
    }

    /**
     * Load the {@see HostProfile} from the repo-root `milpa.json` (its `hostProfile` block — NOT a
     * plugin manifest), mirroring the load path of `coa:inspect architecture`. Returns null when
     * there is no readable profile: the cache-hit resolver gate then does not apply
     * ({@see cachedGraphIsBootable()}) and the fresh path falls back to the permissive profile
     * ({@see permissiveHostProfile()}).
     *
     * A missing file or an absent `hostProfile` key stays silent — that is a legitimate
     * no-profile host. But a `hostProfile` block that EXISTS and is malformed (not an object, or
     * one {@see HostProfile::fromArray()} rejects) logs a notice: the host declared a profile and
     * got no gate, and that gap must never be silent.
     */
    private function loadHostProfile(): ?HostProfile
    {
        $path = $this->config->hostManifestPath;
        if ($path === null || !is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !array_key_exists('hostProfile', $decoded)) {
            return null;
        }

        if (!is_array($decoded['hostProfile'])) {
            $this->logger->notice(
                '[Plugins] milpa.json declares a hostProfile block that is not an object;'
                . ' the architecture gate is off until it is fixed.'
            );

            return null;
        }

        try {
            /** @var array<string, mixed> $profile */
            $profile = $decoded['hostProfile'];

            return HostProfile::fromArray($profile);
        } catch (\Throwable $e) {
            $this->logger->notice(
                '[Plugins] milpa.json declares a malformed hostProfile block (' . $e->getMessage() . ');'
                . ' the architecture gate is off until it is fixed.'
            );

            return null;
        }
    }

    private function writeCache(string $cacheFile): void
    {
        $dir = dirname($cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        // Add class to metadata for reconstruction
        $dataToCache = [];
        foreach ($this->plugins as $plugin) {
            // Attach 'class' so the cache can re-instantiate each plugin.
            $dataToCache[] = $plugin;
        }

        $content = "<?php\nreturn " . var_export($dataToCache, true) . ";\n";
        file_put_contents($cacheFile, $content);
    }

    /**
     * Rebuild the enabled-plugins cache file from the activation store.
     *
     * @param string $cacheFile Path to write the cache file.
     *
     * @return array<string> List of enabled plugin names.
     */
    private function rebuildEnabledPluginsCache(string $cacheFile): array
    {
        $pluginNames = $this->registry->enabledNames();

        // Persist only a non-empty read: [] can mean EITHER "nothing enabled"
        // OR "store down, degraded read" (the port cannot tell them apart), and
        // caching the degraded case would poison every later boot until someone
        // deletes the file by hand. Legacy only wrote after a successful store
        // read — not persisting [] keeps the self-healing property at the cost
        // of one registry read per boot on genuinely-zero-plugin hosts.
        if ($pluginNames !== []) {
            $dir = \dirname($cacheFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            file_put_contents($cacheFile, "<?php\nreturn " . var_export($pluginNames, true) . ";\n");
            $this->logger->debug('[Plugins] Rebuilt enabled plugins cache from the registry: ' . implode(', ', $pluginNames));
        }

        return $pluginNames;
    }

    /**
     * @param array<string, mixed> $metadata Plugin metadata (name, version, etc.), carried on the
     *                                       plugin.booting/plugin.booted lifecycle events.
     */
    private function registerAndBoot(string $className, string $name, array $metadata = []): void
    {
        if (!$this->container->has($className)) {
            $this->container->registerService($className, new $className($this->container));
        }
        $plugin = $this->container->get($className);

        if ($plugin instanceof PluginInterface) {
            if (!$this->bootPlugin($plugin, $name, $metadata)) {
                // Vetoed by a plugin.booting listener via InterceptionSlot::stop() — this
                // plugin's boot() never ran, so it is not tracked as a booted instance and
                // gets no tools/event subscriptions registered. The boot loop continues
                // with the next plugin (see loadPlugins() / loadFromCache()).
                return;
            }

            $this->pluginInstances[$name] = $plugin;

            // Auto-register tools from ToolProviderInterface plugins
            $this->registerPluginTools($plugin, $className);

            // Auto-register event subscriptions from EventSubscriberInterface plugins
            $this->registerPluginEventSubscriptions($plugin, $className);
        }
    }

    /**
     * Register event subscriptions from plugins that implement EventSubscriberInterface.
     */
    private function registerPluginEventSubscriptions(PluginInterface $plugin, string $className): void
    {
        // Check if plugin implements EventSubscriberInterface
        if (!($plugin instanceof \Milpa\Interfaces\Event\EventSubscriberInterface)) {
            return;
        }

        // Get MilpaEventDispatcherInterface from container
        if (!$this->container->has(MilpaEventDispatcherInterface::class)) {
            $this->logger->debug("MilpaEventDispatcherInterface not available, skipping event subscriptions for $className");
            return;
        }

        $dispatcher = $this->container->get(MilpaEventDispatcherInterface::class);
        $subscribedEvents = $className::getSubscribedEvents();

        foreach ($subscribedEvents as $eventName => $config) {
            $methodName = $config['method'] ?? $config;
            $priority = $config['priority'] ?? 0;

            if (is_string($methodName) && method_exists($plugin, $methodName)) {
                $dispatcher->subscribe(
                    $eventName,
                    fn (string $event, array $payload) => $plugin->$methodName($event, $payload),
                    $priority
                );
                $this->logger->debug("Registered event subscription: $className::$methodName -> $eventName");
            }
        }
    }

    /**
     * Register tools from plugins that implement ToolProviderInterface.
     * Respects plugin type and current environment.
     */
    private function registerPluginTools(PluginInterface $plugin, string $className): void
    {
        //$this->logger->debug("[Plugins] Checking tool registration for: $className");

        // Check if plugin implements ToolProviderInterface
        if (!($plugin instanceof \Milpa\Interfaces\Tooling\ToolProviderInterface)) {
            return;
        }

        // Get plugin metadata to check type
        $metadata = $this->getMetadata($className);
        $pluginType = $metadata['type'] ?? 'Web';
        $environment = $this->config->environment;

        // Determine if we should load tools based on type and environment
        $shouldLoadTools = match ($pluginType) {
            'Web' => $environment === 'Web',
            'CLI' => $environment === 'CLI',
            'Mixed', 'Service' => true, // Load in both environments
            default => $environment === 'Web', // Default to Web behavior
        };

        if (!$shouldLoadTools) {
            return;
        }

        // Get the tool registry from the container (core contract key).
        if (!$this->container->has(ToolRegistryInterface::class)) {
            $this->logger->debug("ToolRegistry not available, skipping tool registration for {$metadata['name']}");
            return;
        }

        $toolRegistry = $this->container->get(ToolRegistryInterface::class);

        try {
            $plugin->registerTools($toolRegistry);
        } catch (\Exception $e) {
            $this->logger->error("Error registering tools from {$metadata['name']}: " . $e->getMessage());
        }
    }

    /**
     * Scan plugins in a path and collect metadata WITHOUT booting.
     * Used to enable contract validation and dependency ordering.
     */
    public function scanPluginsPath(string $pluginPath): void
    {
        if ($this->directoryExists($pluginPath)) {
            $directory = new \RecursiveDirectoryIterator($pluginPath);
            $iterator = new \RecursiveIteratorIterator($directory);
            $regex = new \RegexIterator($iterator, '/^.+Plugin\.php$/i', \RegexIterator::GET_MATCH);

            foreach ($regex as $match) {
                $className = $this->getPluginClassNameFromFile(
                    $match[0],
                    $pluginPath,
                    $this->config->namespacePrefix
                );

                if (!class_exists($className)) {
                    $this->logger->warning("PluginSystem class not found: $className");
                    continue;
                }
                $this->scanPlugin($className);
            }
        }
    }

    /**
     * Scan a plugin and collect metadata without booting.
     *
     * @return bool True if plugin was added to the list, false otherwise
     */
    private function scanPlugin(string $className): bool
    {
        try {
            $metadata = $this->getMetadata($className);

            // VALIDATION
            if (empty($metadata['name']) || empty($metadata['version'])) {
                throw new \Exception("Plugin $className missing required metadata (name, version).");
            }

            // Unique check
            foreach ($this->plugins as $p) {
                if (($p['name'] ?? '') === $metadata['name']) {
                    throw new \Exception("Duplicate plugin name: " . $metadata['name']);
                }
            }

            // Check if enabled
            if (!in_array($metadata['name'], $this->enabledPlugins)) {
                $this->logger->debug("Plugin {$metadata['name']} is disabled. Skipping.");
                return false;
            }

            $metadata['class'] = $className; // Store class for caching
            $this->plugins[] = $metadata;

        } catch (\Exception $e) {
            $this->logger->error("Error scanning plugin: {$className}. " . $e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * Plugin metadata read from the #[PluginMetadata] attribute — the single
     * authority for plugin identity (D5). A plugin's milpa.json remains the
     * distribution manifest on disk; divergence between the two surfaces as a
     * doctor parity warning, never as a different boot graph.
     *
     * @param string $className Fully-qualified plugin class name.
     *
     * @return array<string, mixed>
     *
     * @throws \Exception When the class does not exist or declares no metadata.
     */
    public function getMetadata(string $className): array
    {
        if (!class_exists($className)) {
            $this->logger->warning("PluginSystem class not found: $className");
            throw new \Exception("PluginSystem class not found: $className");
        }

        return $this->getMetadataFromAttributes($className);
    }

    /**
     * Read metadata from #[PluginMetadata] PHP attributes.
     *
     * @return array<string, mixed>
     */
    private function getMetadataFromAttributes(string $className): array
    {
        $reflection = new \ReflectionClass($className);

        $attributes = $reflection->getAttributes(PluginMetadata::class);

        if (empty($attributes)) {
            $this->logger->info("PluginSystem {$className} has no metadata defined.");
            throw new \Exception("PluginSystem {$className} has no metadata defined.");
        }

        $metadataInstance = $attributes[0]->newInstance();

        return [
            'version' => $metadataInstance->version,
            'author' => $metadataInstance->author,
            'site' => $metadataInstance->site,
            'name' => $metadataInstance->name,
            'type' => $metadataInstance->type,
            'provides' => $metadataInstance->provides,
            'requires' => $metadataInstance->requires,
            'suggests' => $metadataInstance->suggests,
        ];
    }

    /**
     * Runs the boot() method of a plugin implementing PluginInterface, emitting the
     * 'plugin.booting' (PRE, stoppable via {@see InterceptionSlot}) / 'plugin.booted'
     * (POST, readonly) lifecycle events around the call.
     *
     * A 'plugin.booting' listener can call `$slot->stop()` — for example, a feature-flag
     * or environment plugin vetoing another plugin's activation — to skip this plugin's
     * boot() entirely. In that case boot() never runs, 'plugin.booted' never fires, and
     * this method returns false. The caller (registerAndBoot()) must not treat a false
     * return as a fatal error: the plugin boot loop continues with the next plugin.
     *
     * @param PluginInterface      $plugin   The plugin instance already registered in the container.
     * @param string               $name     The plugin's name (for the lifecycle events).
     * @param array<string, mixed> $metadata Plugin metadata, carried on the events.
     *
     * @return bool True when boot() actually ran; false when a listener vetoed it.
     */
    private function bootPlugin(PluginInterface $plugin, string $name = '', array $metadata = []): bool
    {
        $dispatcher = $this->getEventDispatcher();
        $slot = new InterceptionSlot();

        $dispatcher?->dispatch(
            'plugin.booting',
            ['event' => new PluginBootingEvent($name, $metadata), 'slot' => $slot]
        );

        if ($slot->isStopped()) {
            $this->logger->info("[Plugins] Boot vetoed for plugin '{$name}' by a plugin.booting listener.");
            return false;
        }

        $plugin->boot();

        $dispatcher?->dispatch(
            'plugin.booted',
            ['event' => new PluginBootedEvent($name, $metadata)]
        );

        return true;
    }

    /**
     * Resolves the shared MilpaEventDispatcherInterface from the container, if one is
     * registered. Nullable-safe by design (the HumanVerifier pattern, a family
     * convention): callers invoke it via `?->dispatch(...)` so plugin boot proceeds
     * unaffected when no dispatcher is wired — or when whatever is registered under
     * that key is not actually a dispatcher.
     */
    private function getEventDispatcher(): ?MilpaEventDispatcherInterface
    {
        if (!$this->container->has(MilpaEventDispatcherInterface::class)) {
            return null;
        }

        $dispatcher = $this->container->get(MilpaEventDispatcherInterface::class);

        return $dispatcher instanceof MilpaEventDispatcherInterface ? $dispatcher : null;
    }

    /**
     * Emits 'capability.resolved' (POST, readonly, no slot) once the plugin dependency
     * graph for this boot is finalized.
     *
     * @param array<int, array<string, mixed>> $loadOrder Finalized plugin metadata, in boot order.
     */
    private function emitCapabilityResolved(array $loadOrder): void
    {
        $this->getEventDispatcher()?->dispatch(
            'capability.resolved',
            ['event' => new CapabilityResolvedEvent($loadOrder)]
        );
    }

    /**
     * Emits 'kernel.booted' (POST, readonly, no slot) at the end of the plugin boot
     * cycle, regardless of which branch of loadPlugins() produced it.
     */
    private function emitKernelBooted(): void
    {
        $this->getEventDispatcher()?->dispatch(
            'kernel.booted',
            ['event' => new KernelBootedEvent(array_keys($this->pluginInstances))]
        );
    }

    /**
     * Derives the plugin class name from the plugin file and the base path.
     *
     * - Strips the leading part ($pluginPath) and the .php extension.
     * - Replaces directory separators with namespace separators.
     * - Prepends $namespaceBase to form the fully-qualified namespace.
     *
     * @param string $pluginFile    Absolute path to the plugin file.
     * @param string $pluginPath    Base directory for plugins.
     * @param string $namespaceBase Base namespace for plugins (e.g., "Milpa\Plugins").
     *
     * @return string The fully-namespaced class.
     */
    private function getPluginClassNameFromFile(
        string $pluginFile,
        string $pluginPath,
        string $namespaceBase
    ): string {
        // 1. Remove the base plugin path from the file path.
        $relativePath = str_replace($pluginPath . DIRECTORY_SEPARATOR, '', $pluginFile);

        // 2. Remove the .php extension.
        $relativePath = substr($relativePath, 0, -4);


        // 3. Replace directory separators with namespace separators.
        $className = str_replace(DIRECTORY_SEPARATOR, '\\', $relativePath);


        // 4. Prepend the base namespace and return
        $namedClass = "{$namespaceBase}\\{$className}";


        return $namedClass;
    }

    /**
     * Checks whether a directory exists. Logs an error via the logger when it does not.
     *
     * @param string $directory Path of the directory to validate.
     *
     * @return bool True when the directory exists, false otherwise.
     */
    private function directoryExists(string $directory): bool
    {
        if (!is_dir($directory)) {
            $this->logger->error("Directory '{$directory}' does not exist");
            return false;
        }
        return true;
    }
}
