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
use Milpa\Plugin\Contracts\ActivationSafetyInterface;
use Milpa\Plugin\Contracts\PluginRegistryInterface;
use Milpa\Plugin\Contracts\StateBaselineInterface;
use Milpa\Plugin\LockFileManager;
use Milpa\Plugin\PluginManifest;
use Milpa\Plugin\Runtime\MetadataGraphResolver;
use Milpa\Services\CapabilityMatcher;

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
        // Para contestar «qué se rompe si apago esto» sin intentar apagarlo. Opcional: sin ella, la
        // arquitectura se reporta igual y ese campo va en `null` — decir «nada se rompe» cuando en
        // realidad no se preguntó sería la peor de las respuestas.
        private ?ActivationSafetyInterface $safety = null,
        // El criterio único de identidad de capacidad, compartido con el chequeo pre-boot y el
        // validador de manifiestos. Ver `settlement-q-p17.md`: eran cuatro y no coincidían.
        private CapabilityMatcher $matcher = new CapabilityMatcher(),
        // Con qué estado empezó quien lee. Opcional, y su ausencia se REPORTA: un reporte que callara
        // que no pudo preguntar se leería como «nada ha cambiado», que es la afirmación falsa que
        // Q-P17-J midió costando 10 respuestas erróneas de 12.
        private ?StateBaselineInterface $baseline = null,
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
     * @return array{ok: bool, total: int, loadOrder?: list<array{position: int, plugin: string, provides: list<string>, requires: list<string>}>, error?: string}
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
                // LOS DATOS, no la tabla. Esto devolvía la forma corta ya pintada —y `'—'` cuando la
                // lista venía vacía— así que por `--json` y por MCP un agente recibía un guion donde
                // esperaba una lista y no podía hacer nada con él. Pintar es de la superficie; una
                // operación que ya pintó le quitó la decisión a las otras tres (ADR-0035).
                'provides' => $this->capabilityIds($plugin['provides'] ?? []),
                'requires' => $this->capabilityIds($plugin['requires'] ?? []),
            ];
        }

        return ['ok' => true, 'total' => \count($orden), 'loadOrder' => $orden];
    }

    /**
     * El grafo de esta app como DATO: qué capacidades hay, quién provee cada una, quién la pide, cuál
     * falta, y qué se rompería si apagas cada plugin (P17.2).
     *
     * ── POR QUÉ NO ALCANZABA CON `deps` ─────────────────────────────────────────────────────────
     *
     * `deps` contesta en qué ORDEN arrancan, que es una pregunta de arranque. Las que se hacen cuando
     * alguien —o algo— quiere OPERAR el sistema son otras: «¿quién usa `database`?», «¿qué se cae si
     * apago esto?», «¿qué capacidad quedó sin dueño?». Todas se derivan del mismo grafo y ninguna se
     * podía contestar sin leer plugin por plugin y cruzar a mano.
     *
     * El índice INVERSO es la mitad que faltaba. Un agente que sólo ve «cada plugin declara esto»
     * tiene que reconstruir «quién depende de qué» en su cabeza, en cada vuelta y sin poder
     * verificarlo. Cruzarlo aquí cuesta un bucle y se calcula una vez.
     *
     * ── EL IMPACTO SE PREGUNTA, NO SE INTENTA ───────────────────────────────────────────────────
     *
     * `blockingReasonWithout()` existía y sólo se alcanzaba al NEGAR un `plugins.disable` — o sea que
     * la única forma de saber qué se rompía era intentar romperlo. Preguntar antes de causar es la
     * misma distinción que separa `simulate` de `enable`.
     *
     * @param array<string, mixed> $input
     *
     * @return array{ok: bool, total: int, plugins: list<array<string, mixed>>, capabilities: list<array<string, mixed>>, unsatisfied: list<string>, baseline?: array<string, mixed>|bool|null, error?: string}
     */
    public function architecture(array $input): array
    {
        unset($input);

        $activos = $this->active();
        if ($activos === []) {
            // CON línea base igual: cero plugins activos puede ser una app vacía o una app que el
            // lector acaba de dejar vacía, y son cosas distintas. Sin este `baseline` el segundo caso
            // —el más peligroso de todos— se reportaría como si siempre hubiera sido así.
            return [
                'ok' => true,
                'total' => 0,
                'plugins' => [],
                'capabilities' => [],
                'unsatisfied' => [],
                'baseline' => $this->sinceBaseline([]),
            ];
        }

        $plugins = [];
        /** @var array<string, array{providedBy: list<string>, requiredBy: list<string>}> $indice */
        $indice = [];

        foreach ($activos as $activo) {
            $nombre = \is_string($activo['name'] ?? null) ? $activo['name'] : '?';
            $provee = $this->capabilityIds($activo['provides'] ?? []);
            $pide = $this->capabilityIds($activo['requires'] ?? []);

            foreach ($provee as $id) {
                $indice[$id] ??= ['providedBy' => [], 'requiredBy' => []];
                $indice[$id]['providedBy'][] = $nombre;
            }
            foreach ($pide as $id) {
                $indice[$id] ??= ['providedBy' => [], 'requiredBy' => []];
                $indice[$id]['requiredBy'][] = $nombre;
            }

            $plugins[] = [
                'name' => $nombre,
                'version' => \is_string($activo['version'] ?? null) ? $activo['version'] : '',
                'provides' => $provee,
                'requires' => $pide,
            ];
        }

        // QUÉ SE ROMPE si apagas cada uno, DERIVADO del índice: si es el único proveedor de algo que
        // alguien pide, apagarlo deja a ese alguien sin proveedor.
        //
        // Se calcula aquí y no se le pide sólo a `ActivationSafetyInterface` porque ese contrato
        // necesita un perfil de host, y un host que no lo declara —la app que sale de un
        // `create-project`, por ejemplo— recibía `null` en todos: «no se pudo preguntar» presentado
        // como si fuera la respuesta. La derivación no necesita perfil y contesta el caso común; el
        // contrato, cuando está, contesta mejor porque además conoce ese perfil.
        foreach ($plugins as $i => $plugin) {
            $rompe = null;
            foreach ($plugin['provides'] as $id) {
                $otros = array_values(array_filter(
                    $indice[$id]['providedBy'],
                    static fn (string $n): bool => $n !== $plugin['name'],
                ));
                $usuarios = array_values(array_filter(
                    $indice[$id]['requiredBy'],
                    static fn (string $n): bool => $n !== $plugin['name'],
                ));

                if ($otros === [] && $usuarios !== []) {
                    $rompe = sprintf(
                        'es el único que provee «%s», que %s %s',
                        $id,
                        \count($usuarios) === 1 ? 'necesita' : 'necesitan',
                        implode(', ', $usuarios),
                    );

                    break;
                }
            }

            $plugins[$i]['breaksIfDisabled'] = $this->safety?->blockingReasonWithout($plugin['name']) ?? $rompe;
        }

        // LA LÍNEA BASE. Todo lo de arriba describe el estado ACTUAL, y hasta aquí el reporte no tenía
        // forma de decirlo. Ver {@see StateBaselineInterface}: un agente que apagó un plugin y después
        // leyó este reporte citó `breaksIfDisabled` —cierto, sobre OTRO plugin y una acción futura—
        // como la consecuencia de su propio apagado. 10 de 12 corridas contestaron mal así.
        $linea = $this->sinceBaseline($plugins);
        if (\is_array($linea) && !$linea['unchanged']) {
            // El aviso va PEGADO al campo que se leyó mal, no sólo al pie del reporte. Lo que el
            // modelo cita es la cadena; una nota lejos de ella es una nota que no acompaña al error.
            foreach ($plugins as $i => $plugin) {
                if (\is_string($plugin['breaksIfDisabled'])) {
                    $plugins[$i]['breaksIfDisabled'] .= sprintf(
                        ' — esto describe apagarlo DESDE EL ESTADO ACTUAL, que ya no es el estado con %s: %s',
                        $linea['label'],
                        $linea['note'],
                    );
                }
            }
        }

        $capacidades = [];
        $huerfanas = [];
        foreach ($indice as $id => $lados) {
            $satisfecha = $lados['providedBy'] !== [];
            $capacidades[] = [
                'id' => $id,
                'providedBy' => $lados['providedBy'],
                'requiredBy' => $lados['requiredBy'],
                'satisfied' => $satisfecha,
            ];
            if (!$satisfecha && $lados['requiredBy'] !== []) {
                $huerfanas[] = $id;
            }
        }

        return [
            // El veredicto es si el grafo CIERRA, y por eso no depende de que haya capacidades: una
            // app sin ninguna cierra por vacío. Lo que lo abre es una que alguien pide y nadie da.
            'ok' => $huerfanas === [],
            'total' => \count($plugins),
            'plugins' => $plugins,
            'capabilities' => $capacidades,
            'unsatisfied' => $huerfanas,
            // `null` significa «nadie pudo decir desde cuándo miras», y se distingue de `unchanged:
            // true`, que afirma que no ha cambiado nada. Confundir las dos es exactamente el error
            // que este campo existe para no cometer.
            'baseline' => $linea,
        ];
    }

    /**
     * Qué cambió desde que empezó la vuelta de quien lee.
     *
     * Devuelve `null` cuando nadie puede contestarlo. Ese `null` no es un descuido: un reporte que
     * omitiera el campo se leería como «esto es el mundo», y el mundo del que habla puede llevar tres
     * apagados encima puestos por el propio lector.
     *
     * @param list<array<string, mixed>> $plugins los activos AHORA, ya armados
     *
     * ── LA REGLA SOLO_SI_CAMBIO, Y SU MEDICIÓN ──────────────────────────────────────────────────
     *
     * El bloque se emite **sólo si cambió algo**. Con el bloque en todas las lecturas, la corrección
     * de las corridas que NO habían mutado cayó de 6 de 10 a **1 de 10**
     * (Q-P17-K). Era texto verdadero, corto y
     * exacto, y en 20 de 32 corridas no había absolutamente nada que fechar.
     *
     * Tres respuestas, y las tres distintas — colapsar las dos primeras ahorraría cuatro tokens y
     * borraría la única distinción que protege al lector:
     *
     * - `null`  — nadie pudo llevar la cuenta; algo pudo cambiar sin que se sepa;
     * - `true`  — se llevó, y no cambió nada desde el arranque;
     * - `array` — se llevó, y esto es lo que cambió.
     *
     * Lo que esto NO arregla: que fechar repare la lectura sigue **sin medirse**.
     *
     * @return array{label: string, enabled: list<string>, unchanged: bool, disabledSince: list<string>, enabledSince: list<string>, note: string}|bool|null
     */
    private function sinceBaseline(array $plugins): array|bool|null
    {
        $inicial = $this->baseline?->enabledAtBaseline();
        if ($inicial === null) {
            return null;
        }

        $ahora = [];
        foreach ($plugins as $plugin) {
            if (\is_string($plugin['name'] ?? null)) {
                $ahora[] = $plugin['name'];
            }
        }

        $apagados = array_values(array_diff($inicial, $ahora));
        $encendidos = array_values(array_diff($ahora, $inicial));
        $igual = $apagados === [] && $encendidos === [];

        $label = $this->baseline->baselineLabel();

        // La nota se redacta aquí y no en el host porque tiene que decir la MISMA cosa en las tres
        // superficies. Y dice la consecuencia, no sólo el hecho: «se apagó X» invita a seguir leyendo
        // el reporte como si contestara la pregunta original, y no la contesta.
        if ($igual) {
            $nota = sprintf('nada se ha encendido ni apagado desde %s: este reporte y aquel momento coinciden', $label);
        } else {
            $partes = [];
            if ($apagados !== []) {
                $partes[] = 'se apagó ' . implode(', ', $apagados);
            }
            if ($encendidos !== []) {
                $partes[] = 'se encendió ' . implode(', ', $encendidos);
            }
            $nota = sprintf(
                'desde %s %s. Una pregunta sobre aquel estado NO se contesta con este reporte; para eso está la simulación, que pregunta sin cambiar nada.',
                $label,
                implode(' y ', $partes),
            );
        }

        // AQUÍ SE PAGA O NO SE PAGA. Si nada se movió no hay nada que enmarcar, y emitir el bloque
        // igual costó 5 de 10 respuestas correctas a cambio de decir «no pasó nada».
        if ($igual) {
            return true;
        }

        return [
            'label' => $label,
            'enabled' => $inicial,
            'unchanged' => $igual,
            'disabledSince' => $apagados,
            'enabledSince' => $encendidos,
            'note' => $nota,
        ];
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
     * El criterio NO vive aquí. Vive en {@see CapabilityMatcher}, y esta clase lo consume igual que
     * el chequeo pre-boot y el validador de manifiestos. Tenerlo propio fue el defecto: el inspector
     * ignoraba `oneOf`, así que reportaba como no cubierta una capacidad que el motor sí resolvía —
     * y el inspector es justo la superficie que se ofrece como diagnóstico.
     */
    private function satisfies(mixed $requerida, mixed $provista): bool
    {
        if ((!\is_string($requerida) && !\is_array($requerida)) || (!\is_string($provista) && !\is_array($provista))) {
            return false;
        }

        return $this->matcher->identityMatches($provista, $requerida);
    }

    /** @return list<string> */
    private function identities(mixed $entrada): array
    {
        if (!\is_string($entrada) && !\is_array($entrada)) {
            return [];
        }

        return $this->matcher->identitiesOffered($entrada);
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

    /**
     * Los identificadores de una lista de capacidades, como lista.
     *
     * @return list<string>
     */
    private function capabilityIds(mixed $entradas): array
    {
        if (!\is_array($entradas)) {
            return [];
        }

        $ids = [];
        foreach (array_values($entradas) as $entrada) {
            foreach ($this->identities($entrada) as $id) {
                $ids[] = $id;
            }
        }

        return $ids;
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
