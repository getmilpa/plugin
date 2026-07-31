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
use Milpa\Interfaces\Plugin\PluginInstallerInterface;
use Milpa\Plugin\Contracts\PluginRegistryInterface;
use Milpa\Plugin\LockFileManager;
use Milpa\Plugin\PluginManifest;
use Milpa\Plugin\Runtime\MetadataGraphResolver;

/**
 * Mirar los plugins antes de tocarlos: si el grafo resuelve y en qué orden, qué pasaría al encender
 * uno, si un manifiesto está íntegro, qué tiene actualización, y regenerar el archivo de bloqueo.
 *
 * ── POR QUÉ VIVE AQUÍ Y NO EN UN HOST ───────────────────────────────────────────────────────────
 *
 * Porque todo lo que necesita ya vivía aquí: {@see MetadataGraphResolver} decide si el grafo ordena,
 * {@see PluginManifest} si un manifiesto vale, {@see LockFileManager} escribe el bloqueo. Lo único
 * que ponía el host era la lista de plugins activos —y este paquete la sabe armar desde su registry
 * y la lista declarada, que es la misma que ya usa {@see PluginOperations}.
 *
 * Estas cinco nacieron en un host, donde funcionaban. El problema era el SIGUIENTE host: para poder
 * preguntar si su grafo resuelve tendría que reescribirlas, y esa copia es la que después diverge.
 * Un `composer create-project` las tiene sin escribir una línea.
 *
 * ── NINGUNA ADJUDICA POR SU CUENTA ──────────────────────────────────────────────────────────────
 *
 * Llama y reporta. Un inspector que volviera a decidir sería una segunda autoridad diciendo lo mismo
 * —peor, y más tarde— sobre lo que el resolver y el manifiesto ya decidieron.
 */
final readonly class PluginInspection
{
    /**
     * @param list<class-string> $declared las clases que el host declara en código
     * @param string|null        $root     la raíz de la app; sin ella no se pueden leer manifiestos
     *                                     ni escribir `milpa.lock`, y las operaciones que lo
     *                                     necesitan no se ofrecen
     */
    public function __construct(
        private PluginRegistryInterface $registry,
        private array $declared = [],
        private ?string $root = null,
        private ?PluginInstallerInterface $installer = null,
    ) {
    }

    /**
     * ¿El grafo de plugins activos resuelve, y en qué orden arrancarían?
     *
     * El orden ES el dato, no presentación: es la secuencia en que el runtime los va a arrancar, y
     * quien provee algo tiene que ir antes que quien lo requiere.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, total: int, loadOrder?: list<array{position: int, plugin: string, provides: string, requires: string}>, error?: string}
     */
    public function deps(array $input): array
    {
        unset($input);

        $activos = $this->active();
        if ($activos === []) {
            // Cero plugins y un grafo roto son cosas distintas: contestar `ok: false` aquí mandaría a
            // alguien a buscar una dependencia que no existe.
            return ['ok' => true, 'total' => 0, 'loadOrder' => []];
        }

        try {
            $ordenados = (new MetadataGraphResolver())->order($activos);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            // Las DOS: un grafo que no cierra lanza `RuntimeException`, y un manifiesto malformado
            // —una capacidad sin versión de contrato, digamos— lanza `InvalidArgumentException` desde
            // el resolver. Atrapar sólo la primera dejaba que la segunda se escapara: preguntar si el
            // grafo resuelve terminaba en una traza en vez de en una respuesta, que es justo lo que
            // una operación de sólo lectura no debe hacer.
            return ['ok' => false, 'total' => \count($activos), 'error' => $e->getMessage()];
        }

        $orden = [];
        foreach ($ordenados as $i => $plugin) {
            $orden[] = [
                'position' => $i + 1,
                'plugin' => \is_string($plugin['name'] ?? null) ? $plugin['name'] : '?',
                'provides' => $this->labels($plugin['provides'] ?? []),
                'requires' => $this->labels($plugin['requires'] ?? []),
            ];
        }

        return ['ok' => true, 'total' => \count($orden), 'loadOrder' => $orden];
    }

    /**
     * ¿Qué pasaría si se encendiera este plugin?
     *
     * Resuelve el grafo con el candidato adentro SIN encender nada: es la forma de preguntar antes de
     * causar, y la que una superficie de agente debería usar antes de `plugins.enable`.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, plugin: string, wouldResolve?: bool, alreadyEnabled?: bool, requirements?: list<array{capability: string, providedBy: string}>, error?: string}
     */
    public function simulate(array $input): array
    {
        $nombre = $this->nameOf($input);
        if ($nombre === '') {
            return ['ok' => false, 'plugin' => '', 'error' => 'falta `plugin`'];
        }

        $clase = $this->classOf($nombre);
        if ($clase === null) {
            return ['ok' => false, 'plugin' => $nombre, 'error' => "no existe el plugin «{$nombre}»"];
        }

        $registro = $this->registry->find($nombre);
        if ($registro !== null && $registro->enabled) {
            // Un plugin ya encendido no se simula: la respuesta honesta es que ya está, no un grafo
            // hipotético idéntico al real.
            return ['ok' => true, 'plugin' => $nombre, 'alreadyEnabled' => true];
        }

        $activos = $this->active();
        $candidato = array_merge($this->metadata($clase), ['name' => $nombre, 'class' => $clase]);

        try {
            (new MetadataGraphResolver())->order([...$activos, $candidato]);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            // Igual que en `deps()`: un manifiesto malformado también es una respuesta, no una traza.
            return ['ok' => false, 'plugin' => $nombre, 'wouldResolve' => false, 'error' => $e->getMessage()];
        }

        // Quién satisface cada requisito, NOMBRADO. «Resolvería» sin decir gracias a quién deja a
        // quien pregunta sin saber qué se rompería al apagar otra cosa.
        $requisitos = [];
        /** @var list<mixed> $requiere */
        $requiere = \is_array($candidato['requires'] ?? null) ? array_values($candidato['requires']) : [];
        foreach ($requiere as $capacidad) {
            $requisitos[] = [
                'capability' => $this->label($capacidad),
                'providedBy' => $this->providerOf($capacidad, $activos),
            ];
        }

        return ['ok' => true, 'plugin' => $nombre, 'alreadyEnabled' => false, 'wouldResolve' => true, 'requirements' => $requisitos];
    }

    /**
     * ¿El manifiesto existe, es válido, y coincide con lo que el atributo declara?
     *
     * Las tres preguntas se reportan por separado: colapsarlas en un `ok` obligaría a correr algo más
     * para saber cuál de las tres falló. Que NO haya manifiesto tampoco es una falla —un plugin puede
     * vivir sólo con su atributo—, así que se dice y ya.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, plugin: string, checks: list<array{check: string, ok: bool, detail: string}>, error?: string}
     */
    public function verify(array $input): array
    {
        $nombre = $this->nameOf($input);
        if ($nombre === '') {
            return ['ok' => false, 'plugin' => '', 'checks' => [], 'error' => 'falta `plugin`'];
        }

        $clase = $this->classOf($nombre);
        if ($clase === null) {
            return ['ok' => false, 'plugin' => $nombre, 'checks' => [], 'error' => "no existe el plugin «{$nombre}»"];
        }

        $manifiesto = $this->root . '/plugins/' . $nombre . '/milpa.json';
        if (!is_file($manifiesto)) {
            return ['ok' => true, 'plugin' => $nombre, 'checks' => [[
                'check' => 'manifest',
                'ok' => true,
                'detail' => 'sin milpa.json — este plugin vive sólo con su #[PluginMetadata]',
            ]]];
        }

        $checks = [['check' => 'manifest', 'ok' => true, 'detail' => 'milpa.json presente']];

        try {
            $leido = PluginManifest::fromPath($manifiesto);
            $leido->validate();
            $checks[] = ['check' => 'shape', 'ok' => true, 'detail' => 'el manifiesto valida'];
        } catch (\Throwable $e) {
            $checks[] = ['check' => 'shape', 'ok' => false, 'detail' => $e->getMessage()];

            return ['ok' => false, 'plugin' => $nombre, 'checks' => $checks];
        }

        // La paridad se comprueba sobre la VERSIÓN, que es el campo por el que un manifiesto viejo
        // hace daño: dice una cosa a quien lee el disco y otra a quien arranca el código.
        $declarada = (string) ($this->metadata($clase)['version'] ?? '?');
        $enManifiesto = (string) $leido->getVersion();
        $coinciden = $enManifiesto === $declarada;
        $checks[] = [
            'check' => 'parity',
            'ok' => $coinciden,
            'detail' => $coinciden
                ? "versión {$enManifiesto} en los dos"
                : "milpa.json dice {$enManifiesto} y el atributo dice {$declarada}",
        ];

        return ['ok' => array_filter($checks, static fn (array $c): bool => !$c['ok']) === [], 'plugin' => $nombre, 'checks' => $checks];
    }

    /**
     * Qué plugins remotos tienen una versión más nueva disponible.
     *
     * ── UNA LIMITACIÓN QUE SE HEREDA, DICHA ─────────────────────────────────────────────────────
     *
     * El instalador SALTA en silencio una fuente que no contestó. Así que «ninguno desfasado» puede
     * significar «ninguno» o «no pude preguntar», y desde aquí no se distinguen: se reporta `checked`
     * para que el conteo al menos lo insinúe. El arreglo pertenece al instalador.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, checked: int, outdated: list<array<string, mixed>>, error?: string}
     */
    public function outdated(array $input): array
    {
        unset($input);

        $installer = $this->installer;
        // `checkOutdated()` no está en la interfaz, sólo en la implementación de este paquete. Se
        // comprueba en vez de asumirlo: un host puede cablear otro instalador que cumpla el contrato
        // sin ofrecer esta consulta, y ahí la respuesta honesta es que no se puede.
        if ($installer === null || !method_exists($installer, 'checkOutdated')) {
            return ['ok' => false, 'checked' => 0, 'outdated' => [], 'error' => 'el instalador de este host no sabe consultar actualizaciones'];
        }

        $remotos = 0;
        foreach ($this->registry->installed() as $registro) {
            if ($registro->source !== null && $registro->source !== 'local' && $registro->source !== 'declared') {
                ++$remotos;
            }
        }

        /** @var list<array<string, mixed>> $desfasados */
        $desfasados = $installer->checkOutdated();

        return ['ok' => true, 'checked' => $remotos, 'outdated' => $desfasados];
    }

    /**
     * Regenera `milpa.lock` desde lo que el registry dice que está instalado.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, path: string, plugins: int, integrity: bool}
     */
    public function lock(array $input): array
    {
        unset($input);

        $gestor = new LockFileManager((string) $this->root);

        $datos = [];
        foreach ($this->registry->installed() as $registro) {
            $datos[] = [
                'name' => $registro->name,
                'version' => $registro->version,
                'source' => $registro->source ?? 'local',
                'installedAt' => $registro->installedAt?->format('c') ?? (new \DateTimeImmutable())->format('c'),
                'composerDeps' => $registro->composerDeps,
            ];
        }

        $gestor->generate($datos);

        return ['ok' => true, 'path' => $gestor->getPath(), 'plugins' => \count($datos), 'integrity' => $gestor->verify()];
    }

    /**
     * Los plugins que ARRANCAN, con lo que cada uno declara.
     *
     * Instalado y habilitado son dos condiciones y no la misma: uno instalado pero apagado existe en
     * disco y no arranca, así que incluirlo diría que sus capacidades están disponibles cuando no.
     *
     * @return list<array<string, mixed>>
     */
    public function active(): array
    {
        $activos = [];

        foreach ($this->declared as $clase) {
            $registro = $this->registry->find($this->shortName($clase));
            if ($registro !== null && !$registro->enabled) {
                continue;   // declarado pero apagado: el registro manda
            }
            $meta = $this->safeMetadata($clase);
            if ($meta !== null) {
                $activos[$meta['name']] = array_merge($meta, ['class' => $clase]);
            }
        }

        foreach ($this->registry->installedAndEnabled() as $registro) {
            if (isset($activos[$registro->name])) {
                continue;
            }
            $clase = $this->classOf($registro->name);
            $meta = $clase === null ? null : $this->safeMetadata($clase);
            if ($meta !== null) {
                $activos[$meta['name']] = array_merge($meta, ['class' => $clase]);
            }
        }

        return array_values($activos);
    }

    /**
     * Lo que un plugin DECLARA en su atributo.
     *
     * @param class-string $clase
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException si la clase no declara `#[PluginMetadata]`
     */
    public function metadata(string $clase): array
    {
        $meta = $this->safeMetadata($clase);
        if ($meta === null) {
            throw new \RuntimeException("{$clase} no declara #[PluginMetadata]");
        }

        return $meta;
    }

    /**
     * La clase de un plugin por su nombre corto, entre lo declarado y lo instalado.
     *
     * A diferencia de un host, este paquete NO escanea disco: no sabe cómo cada app acomoda sus
     * plugins. Busca entre las clases que el host declaró y, si no, prueba la convención
     * `Milpa\Plugins\<Nombre>\<Nombre>` que usa el instalador al desempacar.
     *
     * @return class-string|null
     */
    public function classOf(string $nombre): ?string
    {
        foreach ($this->declared as $clase) {
            if ($this->shortName($clase) === $nombre || ($this->safeMetadata($clase)['name'] ?? null) === $nombre) {
                return $clase;
            }
        }

        /** @var class-string $convencion */
        $convencion = 'Milpa\\Plugins\\' . $nombre . '\\' . $nombre;

        return class_exists($convencion) ? $convencion : null;
    }

    /** Si esta instancia puede leer manifiestos y escribir el archivo de bloqueo. */
    public function hasRoot(): bool
    {
        return $this->root !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeMetadata(string $clase): ?array
    {
        if (!class_exists($clase)) {
            return null;
        }

        $atributos = (new \ReflectionClass($clase))->getAttributes(PluginMetadata::class);
        if ($atributos === []) {
            return null;
        }

        $meta = $atributos[0]->newInstance();

        return [
            'version' => $meta->version,
            'author' => $meta->author,
            'site' => $meta->site,
            'name' => $meta->name,
            'type' => $meta->type,
            'provides' => $meta->provides,
            'requires' => $meta->requires,
            'suggests' => $meta->suggests,
        ];
    }

    /**
     * Quién provee una capacidad entre los activos, o que nadie.
     *
     * @param list<array<string, mixed>> $activos
     */
    private function providerOf(mixed $capacidad, array $activos): string
    {
        foreach ($activos as $plugin) {
            /** @var list<mixed> $provee */
            $provee = \is_array($plugin['provides'] ?? null) ? array_values($plugin['provides']) : [];
            foreach ($provee as $provision) {
                if ($this->satisfies($capacidad, $provision)) {
                    return \is_string($plugin['name'] ?? null) ? $plugin['name'] : '?';
                }
            }
        }

        return '(nadie)';
    }

    /**
     * Si una capacidad requerida la satisface una provista.
     *
     * Compara IDENTIDADES y no la forma de la entrada: la familia escribe una capacidad como cadena
     * suelta o como registro con `id`/`interface`, y las dos formas nombran lo mismo. Comparar
     * estructuras diría que no encajan por estar escritas distinto.
     */
    private function satisfies(mixed $requerida, mixed $provista): bool
    {
        $quiere = $this->identities($requerida);

        return $quiere !== [] && array_intersect($quiere, $this->identities($provista)) !== [];
    }

    /** @return list<string> */
    private function identities(mixed $entrada): array
    {
        if (\is_string($entrada)) {
            return trim($entrada) === '' ? [] : [trim($entrada)];
        }
        if (!\is_array($entrada)) {
            return [];
        }

        $ids = [];
        foreach (['id', 'interface'] as $llave) {
            $valor = $entrada[$llave] ?? null;
            if (\is_string($valor) && trim($valor) !== '') {
                $ids[] = trim($valor);
            }
        }

        return $ids;
    }

    /** El nombre legible de una capacidad: su id, o la forma corta de su interfaz. */
    private function label(mixed $entrada): string
    {
        $ids = $this->identities($entrada);
        if ($ids === []) {
            return '?';
        }
        $partes = explode('\\', $ids[0]);

        return end($partes);
    }

    /** Las capacidades de una lista, en su forma corta y separadas por coma. */
    private function labels(mixed $entradas): string
    {
        if (!\is_array($entradas) || $entradas === []) {
            return '—';
        }

        return implode(', ', array_map(fn (mixed $e): string => $this->label($e), array_values($entradas)));
    }

    /** @param array<string, mixed> $input */
    private function nameOf(array $input): string
    {
        return \is_string($input['plugin'] ?? null) ? $input['plugin'] : '';
    }

    private function shortName(string $clase): string
    {
        $partes = explode('\\', $clase);

        return end($partes);
    }
}
