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

namespace Milpa\Plugin\Tests\Runtime;

use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Plugin\Registry\InMemoryPluginRegistry;
use Milpa\Plugin\Runtime\ManagerConfig;
use Milpa\Plugin\Runtime\PluginsManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * La comprobación que evita apagar el único proveedor de una capacidad que el host exige.
 *
 * Resuelve el MISMO grafo que resuelve el arranque, quitando ese plugin: es la única forma de que la
 * respuesta valga, porque cualquier otro criterio —contar dependientes, mirar el manifiesto— contesta
 * una pregunta parecida y no la que importa, que es si el siguiente arranque va a pasar.
 *
 * La pregunta se hace ANTES de apagar porque después ya no se puede: un host bloqueado no arranca, y
 * `plugins.enable` necesita que arranque. Pasó una vez y hubo que reencender por SQL directo.
 */
final class PluginsManagerActivationSafetyTest extends TestCase
{
    private string $tmp;

    /** @var list<array{level: string, message: string}> */
    private array $logRecords = [];

    private DIContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'milpa-activation-safety-' . uniqid();
        mkdir($this->tmp . '/storage/cache', 0777, true);
        mkdir($this->tmp . '/plugins', 0777, true);

        $logRecords = &$this->logRecords;
        $logger = new class ($logRecords) extends AbstractLogger {
            /** @param list<array{level: string, message: string}> $records */
            public function __construct(private array &$records)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message];
            }
        };

        $this->container = $this->createMock(DIContainerInterface::class);
        $this->container->method('get')->willReturnCallback(
            static fn ($id) => $id === LoggerInterface::class ? $logger : null
        );
        $this->container->method('has')->willReturn(false);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmp);
        parent::tearDown();
    }

    /**
     * Apagar al ÚNICO proveedor de una capacidad que el perfil exige se contesta con el motivo.
     *
     * El motivo es la primera línea aprendible del reporte —código, por qué, cómo arreglarlo— y no un
     * «no se puede»: quien la lee está por decidir qué instalar antes de volver a intentarlo.
     */
    public function testDisablingTheOnlyProviderOfARequiredCapabilityIsRefusedWithTheReason(): void
    {
        $manager = $this->bootedWith(
            requiredCapabilities: ['Milpa\\Fixtures\\SafetyContract'],
            fixtures: [
                'SafetyProviderFixture' => ['Milpa\\Fixtures\\SafetyContract'],
                'SafetySpareFixture' => [],
            ],
        );

        $motivo = $manager->blockingReasonWithout('SafetyProviderFixture');

        self::assertNotNull($motivo, 'quitar al único proveedor deja el perfil sin satisfacer');
        self::assertStringContainsString('MILPA_CAPABILITY_MISSING', $motivo);
        self::assertStringContainsString('SafetyContract', $motivo);
    }

    /** Un plugin del que nadie depende se apaga sin objeción. */
    public function testDisablingSomethingNobodyNeedsIsAllowed(): void
    {
        $manager = $this->bootedWith(
            requiredCapabilities: ['Milpa\\Fixtures\\SafetyContract'],
            fixtures: [
                'SafetyProviderFixture' => ['Milpa\\Fixtures\\SafetyContract'],
                'SafetySpareFixture' => [],
            ],
        );

        self::assertNull($manager->blockingReasonWithout('SafetySpareFixture'));
    }

    /**
     * Un nombre que no está encendido no cambia el grafo, así que no se resuelve nada.
     *
     * Importa por lo que evita: resolver de más en cada apagado de algo que ya estaba apagado, y —
     * peor— contestar «bloqueado» por un estado que la operación no iba a tocar.
     */
    public function testANameThatIsNotRunningChangesNothing(): void
    {
        $manager = $this->bootedWith(
            requiredCapabilities: [],
            fixtures: ['SafetySpareFixture' => []],
        );

        self::assertNull($manager->blockingReasonWithout('NoEstaEncendido'));
    }

    /**
     * Sin perfil de host declarado no hay nada que exigir, y no exigir no es bloquear.
     *
     * Un host que no declara perfil es legítimo —la mayoría empieza así— y negarle apagar sus propios
     * plugins por falta de información sería inventarse un requisito que nadie escribió.
     */
    public function testAHostWithNoProfileIsNotSecondGuessed(): void
    {
        $manager = $this->bootedWith(
            requiredCapabilities: null,
            fixtures: ['SafetyProviderFixture' => ['Milpa\\Fixtures\\SafetyContract']],
        );

        self::assertNull($manager->blockingReasonWithout('SafetyProviderFixture'));
    }

    /**
     * Arranca un manager con esas capacidades exigidas y esos plugins encendidos.
     *
     * @param list<string>|null           $requiredCapabilities `null` escribe NINGÚN perfil de host
     * @param array<string, list<string>> $fixtures             nombre => lo que provee
     */
    private function bootedWith(?array $requiredCapabilities, array $fixtures): PluginsManager
    {
        if ($requiredCapabilities !== null) {
            file_put_contents($this->tmp . '/milpa.json', (string) json_encode([
                'hostProfile' => [
                    'name' => 'test-host',
                    'version' => '1.0.0',
                    'requiredContracts' => [],
                    'requiredCapabilities' => $requiredCapabilities,
                    'enabledSurfaces' => ['cli'],
                    'allowedLegacyContracts' => [],
                    'acceptedRisks' => [],
                ],
            ], JSON_PRETTY_PRINT));
        }

        foreach ($fixtures as $nombre => $provides) {
            $this->writePlugin($nombre, $provides);
        }

        file_put_contents(
            $this->tmp . '/storage/cache/enabled_plugins.php',
            "<?php\nreturn " . var_export(array_keys($fixtures), true) . ";\n"
        );

        $manager = new PluginsManager(
            $this->container,
            new InMemoryPluginRegistry(),
            new ManagerConfig(
                cacheDir: $this->tmp . '/storage/cache',
                hostManifestPath: $this->tmp . '/milpa.json',
                devMode: false,
                environment: 'CLI',
            ),
        );
        $manager->addPluginPath($this->tmp . '/plugins');
        $manager->loadPlugins();

        return $manager;
    }

    /**
     * Un plugin de utilería con la convención que el escaneo espera: directorio y clase
     * `<Nombre>Plugin`, y el nombre corto en el atributo — que es el que la lista de encendidos usa.
     *
     * @param list<string> $provides
     */
    private function writePlugin(string $name, array $provides): void
    {
        $dir = $this->tmp . '/plugins/' . $name . 'Plugin';
        mkdir($dir, 0777, true);

        $providesPhp = var_export($provides, true);
        $file = $dir . '/' . $name . 'Plugin.php';
        file_put_contents($file, <<<PHP
            <?php

            declare(strict_types=1);

            namespace Milpa\\Plugins\\{$name}Plugin;

            use Milpa\\Attributes\\PluginMetadata;

            #[PluginMetadata(
                version: '1.0.0',
                author: 'Acme',
                site: 'https://teamx.agency',
                name: '{$name}',
                type: 'Service',
                provides: {$providesPhp},
                requires: []
            )]
            class {$name}Plugin
            {
                public function __construct(private mixed \$container)
                {
                }
            }
            PHP);

        // Cada prueba escribe en un $tmp nuevo pero el FQCN sale sólo del nombre, y el contenido es
        // idéntico para un nombre dado: cargarlo dos veces sería una redeclaración fatal.
        $fqcn = "Milpa\\Plugins\\{$name}Plugin\\{$name}Plugin";
        if (!class_exists($fqcn, false)) {
            require_once $file;
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
