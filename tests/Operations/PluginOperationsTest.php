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

namespace Milpa\Plugin\Tests\Operations;

use Milpa\Command\Operation;
use Milpa\DTO\DependencyResolution;
use Milpa\DTO\PluginInstallResult;
use Milpa\DTO\PluginRemoveResult;
use Milpa\Interfaces\Plugin\PluginInstallerInterface;
use Milpa\Plugin\Contracts\PluginRecord;
use Milpa\Plugin\Operations\PluginOperations;
use Milpa\Plugin\Registry\InMemoryPluginRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Plugin management as operations.
 *
 * The handlers are called directly here, the way a projector calls them: the
 * point of the atom is that CLI, HTTP and MCP all arrive at exactly this, so
 * what is worth pinning is the behaviour, the shape it returns, and the
 * metadata each surface reads to decide what to do — is it mutating, does it
 * need confirming, what scope does it want.
 */
final class PluginOperationsTest extends TestCase
{
    private InMemoryPluginRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new InMemoryPluginRegistry();
    }

    private function record(string $name, bool $enabled = true, ?string $source = 'local'): PluginRecord
    {
        return new PluginRecord(
            name: $name,
            version: '1.0.0',
            author: 'Acme',
            site: 'https://example.com',
            type: 'Service',
            installed: true,
            enabled: $enabled,
            source: $source,
            installedVersion: $source !== null && $source !== 'local' ? '1.2.0' : null,
            installedAt: $source !== null && $source !== 'local' ? new \DateTimeImmutable('2026-01-15T10:00:00+00:00') : null,
        );
    }

    /**
     * @param list<class-string> $declared
     *
     * @return array<string, Operation>
     */
    private function operations(?PluginInstallerInterface $installer = null, array $declared = []): array
    {
        $byName = [];
        foreach ((new PluginOperations($this->registry, $installer, $declared))->operations() as $operation) {
            $byName[$operation->name] = $operation;
        }

        return $byName;
    }

    /**
     * Las mismas, pero con una raíz de app — para ver aparecer las dos que tocan disco.
     *
     * @return array<string, \Milpa\Command\Operation>
     */
    private function operationsWithRoot(string $root): array
    {
        $byName = [];
        foreach ((new PluginOperations($this->registry, null, [], null, $root))->operations() as $operation) {
            $byName[$operation->name] = $operation;
        }

        return $byName;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<class-string>   $declared
     */
    private function call(string $name, array $input = [], ?PluginInstallerInterface $installer = null, array $declared = []): mixed
    {
        $operation = $this->operations($installer, $declared)[$name] ?? self::fail("No operation named {$name}.");

        return ($operation->handler)($input);
    }

    // =========================================================================
    // what exists, and what a surface reads off it
    // =========================================================================

    public function testAHostWithOnlyARegistryGetsTheReadAndToggleOperations(): void
    {
        // `deps` and `simulate` come with the registry because that is all they need: the graph is
        // read from what the host declared plus what the store says boots.
        self::assertSame(
            ['plugins.list', 'plugins.show', 'plugins.enable', 'plugins.disable', 'plugins.deps', 'plugins.simulate'],
            array_keys($this->operations()),
        );
    }

    public function testAHostWithAnInstallerAlsoGetsTheOnesThatReachTheNetwork(): void
    {
        // A host that never wired an installer must not be handed an install
        // button: the panel would render it and it would fail when pressed.
        self::assertSame(
            [
                'plugins.list', 'plugins.show', 'plugins.enable', 'plugins.disable',
                'plugins.deps', 'plugins.simulate',
                'plugins.outdated', 'plugins.install', 'plugins.update', 'plugins.remove',
            ],
            array_keys($this->operations($this->installer())),
        );
    }

    /**
     * Sin raíz de la app NO aparecen las dos que tocan disco.
     *
     * Un paquete no adivina dónde vive quien lo instala: `verify` leería un `milpa.json` de una ruta
     * inventada y `lock` escribiría el archivo de bloqueo en otra. La respuesta honesta es no
     * ofrecerlas — la misma postura que ya se toma con el instalador ausente.
     */
    public function testTheTwoThatTouchDiskAppearOnlyWithARoot(): void
    {
        $sinRaiz = array_keys($this->operations());
        self::assertNotContains('plugins.verify', $sinRaiz);
        self::assertNotContains('plugins.lock', $sinRaiz);

        $conRaiz = array_keys($this->operationsWithRoot(sys_get_temp_dir()));
        self::assertContains('plugins.verify', $conRaiz);
        self::assertContains('plugins.lock', $conRaiz);
    }

    public function testReadingIsNotMutatingAndWritingIs(): void
    {
        $operations = $this->operations($this->installer());

        self::assertFalse($operations['plugins.list']->mutating);
        self::assertFalse($operations['plugins.show']->mutating);
        self::assertTrue($operations['plugins.enable']->mutating);
        self::assertTrue($operations['plugins.disable']->mutating);
        self::assertTrue($operations['plugins.install']->mutating);
    }

    public function testOnlyTheOperationsThatRunSomebodyElsesCodeAskToBeConfirmed(): void
    {
        // Enabling a plugin already on disk is reversible with one more call;
        // installing runs code that was not here a moment ago and can pull
        // composer packages with it. Only the second kind stops to ask.
        $operations = $this->operations($this->installer());

        self::assertFalse($operations['plugins.enable']->requiresConfirmation);
        self::assertTrue($operations['plugins.install']->requiresConfirmation);
        self::assertTrue($operations['plugins.update']->requiresConfirmation);
        self::assertTrue($operations['plugins.remove']->requiresConfirmation);
    }

    public function testInstallingIsGuardedBySomethingStricterThanWriting(): void
    {
        $operations = $this->operations($this->installer());

        self::assertSame(['plugins:read'], $operations['plugins.list']->scopes);
        self::assertSame(['plugins:write'], $operations['plugins.enable']->scopes);
        self::assertSame(['plugins:install'], $operations['plugins.install']->scopes);
    }

    public function testEveryOperationDeclaresAnHttpPath(): void
    {
        foreach ($this->operations($this->installer()) as $name => $operation) {
            self::assertNotNull($operation->path, "{$name} has no path, so the HTTP projector has to invent one.");
        }
    }

    // =========================================================================
    // listing
    // =========================================================================

    public function testListingReportsEveryInstalledPluginInAShapeASurfaceCanRender(): void
    {
        $this->registry->register($this->record('MailPlugin', source: 'github:acme/mail-plugin'));

        $result = $this->call('plugins.list');

        self::assertSame([[
            'name' => 'MailPlugin',
            'version' => '1.2.0',
            'author' => 'Acme',
            'site' => 'https://example.com',
            'type' => 'Service',
            'installed' => true,
            'enabled' => true,
            'source' => 'github:acme/mail-plugin',
            'installedAt' => '2026-01-15T10:00:00+00:00',
        ]], $result['plugins']);
    }

    public function testTheVersionShownIsTheOneActuallyInstalled(): void
    {
        // A record carries both the declared version and the one a remote
        // install actually landed. Showing the declared one would tell a
        // person they are running something they are not.
        $this->registry->register($this->record('MailPlugin', source: 'github:acme/mail-plugin'));
        $this->registry->register($this->record('LocalPlugin'));

        $versions = array_column($this->call('plugins.list')['plugins'], 'version', 'name');

        self::assertSame(['MailPlugin' => '1.2.0', 'LocalPlugin' => '1.0.0'], $versions);
    }

    public function testListingCanBeNarrowedToWhatActuallyBoots(): void
    {
        $this->registry->register($this->record('On'));
        $this->registry->register($this->record('Off', enabled: false));

        self::assertSame(['On', 'Off'], array_column($this->call('plugins.list')['plugins'], 'name'));
        self::assertSame(['On'], array_column($this->call('plugins.list', ['enabledOnly' => true])['plugins'], 'name'));
    }

    public function testListingAnEmptyHostIsAnEmptyListAndNotAFailure(): void
    {
        self::assertSame(['plugins' => []], $this->call('plugins.list'));
    }

    public function testShowingOnePluginReturnsTheSameShapeAsALine(): void
    {
        $this->registry->register($this->record('MailPlugin'));

        self::assertSame($this->call('plugins.list')['plugins'][0], $this->call('plugins.show', ['name' => 'MailPlugin']));
    }

    // =========================================================================
    // toggling
    // =========================================================================

    public function testEnablingAndDisablingMoveTheFlagAndSayWhatTheyDid(): void
    {
        $this->registry->register($this->record('MailPlugin', enabled: false));

        self::assertSame(['name' => 'MailPlugin', 'enabled' => true], $this->call('plugins.enable', ['name' => 'MailPlugin']));
        self::assertTrue($this->registry->find('MailPlugin')?->enabled);

        self::assertSame(['name' => 'MailPlugin', 'enabled' => false], $this->call('plugins.disable', ['name' => 'MailPlugin']));
        self::assertFalse($this->registry->find('MailPlugin')?->enabled);
    }

    public function testTogglingAPluginThatIsNotThereSaysSo(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Plugin Ghost is not installed.');

        $this->call('plugins.enable', ['name' => 'Ghost']);
    }

    public function testAnOperationThatNamesAPluginRefusesAnEmptyName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A plugin name is required.');

        $this->call('plugins.show', ['name' => '']);
    }

    // =========================================================================
    // installing, updating, removing
    // =========================================================================

    public function testInstallingReportsWhatLandedAndLeavesItSwitchedOff(): void
    {
        // Installing is not consenting to run it. Booting somebody else's code
        // the instant it arrives takes away the one moment a person has to
        // look at it first.
        $result = $this->call('plugins.install', ['source' => 'acme/mail-plugin:^2.0'], $this->installer());

        self::assertSame('MailPlugin', $result['name']);
        self::assertSame('2.0.0', $result['version']);
        self::assertFalse($result['enabled']);
        self::assertSame(['acme/lib' => '^1.0'], $result['composerPackagesInstalled']);
        self::assertSame(2, $result['migrationsExecuted']);
    }

    public function testInstallingWithoutASourceSaysWhatIsMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A source is required');

        $this->call('plugins.install', [], $this->installer());
    }

    public function testAFailedInstallSurfacesTheReasonRatherThanAFalse(): void
    {
        $installer = $this->installer(installResult: new PluginInstallResult(
            success: false,
            pluginName: 'MailPlugin',
            version: '0.0.0',
            source: 'acme/mail-plugin',
            error: 'Missing required plugins: QueuePlugin',
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing required plugins: QueuePlugin');

        $this->call('plugins.install', ['source' => 'acme/mail-plugin'], $installer);
    }

    public function testUpdatingCarriesTheNoteTheInstallerAttachedToASuccess(): void
    {
        // "Already at latest" arrives as a successful result with a message.
        // Collapsing that into a bare true would leave a person pressing
        // update and seeing nothing happen, twice.
        $this->registry->register($this->record('MailPlugin', source: 'github:acme/mail-plugin'));
        $installer = $this->installer(updateResult: new PluginInstallResult(
            success: true,
            pluginName: 'MailPlugin',
            version: '1.2.0',
            source: 'github:acme/mail-plugin',
            error: 'Already at latest version v1.2.0',
        ));

        $result = $this->call('plugins.update', ['name' => 'MailPlugin'], $installer);

        self::assertSame('1.2.0', $result['version']);
        self::assertSame('Already at latest version v1.2.0', $result['note']);
    }

    public function testUpdatingPassesTheRequestedVersionThroughAndAnEmptyOneAsNone(): void
    {
        $this->registry->register($this->record('MailPlugin', source: 'github:acme/mail-plugin'));
        $installer = $this->installer();

        $this->call('plugins.update', ['name' => 'MailPlugin', 'version' => '^2.0'], $installer);
        $this->call('plugins.update', ['name' => 'MailPlugin', 'version' => ''], $installer);

        self::assertSame([['MailPlugin', '^2.0'], ['MailPlugin', null]], $installer->updates);
    }

    public function testUpdatingSomethingThatIsNotInstalledStopsBeforeTheNetwork(): void
    {
        $installer = $this->installer();

        try {
            $this->call('plugins.update', ['name' => 'Ghost'], $installer);
            self::fail('Expected a RuntimeException.');
        } catch (\RuntimeException $e) {
            self::assertSame('Plugin Ghost is not installed.', $e->getMessage());
        }

        self::assertSame([], $installer->updates, 'The guard must fire before the installer is called.');
    }

    public function testRemovingReportsWhetherTheDataWasKept(): void
    {
        $this->registry->register($this->record('MailPlugin', source: 'github:acme/mail-plugin'));
        $installer = $this->installer();

        $result = $this->call('plugins.remove', ['name' => 'MailPlugin', 'keepData' => true], $installer);

        self::assertSame(['name' => 'MailPlugin', 'removed' => true, 'dataKept' => true], $result);
        self::assertSame([['MailPlugin', true]], $installer->removals);
    }

    public function testRemovingDefaultsToTakingTheDataWithIt(): void
    {
        $this->registry->register($this->record('MailPlugin', source: 'github:acme/mail-plugin'));
        $installer = $this->installer();

        $this->call('plugins.remove', ['name' => 'MailPlugin'], $installer);

        self::assertSame([['MailPlugin', false]], $installer->removals);
    }

    public function testAFailedRemovalSurfacesTheReason(): void
    {
        $this->registry->register($this->record('LocalPlugin'));
        $installer = $this->installer(removeResult: PluginRemoveResult::failure('LocalPlugin', 'Plugin LocalPlugin was installed locally.'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('installed locally');

        $this->call('plugins.remove', ['name' => 'LocalPlugin'], $installer);
    }


    // =========================================================================
    // what the host declares in code
    // =========================================================================

    public function testAPluginDeclaredInCodeIsReportedEvenWithNoRecordAtAll(): void
    {
        // The state a freshly-installed app is in: two plugins running, an
        // empty store. Reporting nothing here would show an empty panel to an
        // app that is running plugins right now.
        $result = $this->call('plugins.list', [], null, [DeclaredFixturePlugin::class]);

        self::assertSame([[
            'name' => 'DeclaredFixture',
            'version' => '2.1.0',
            'author' => 'Acme',
            'site' => 'https://example.com',
            'type' => 'Service',
            'installed' => true,
            'enabled' => true,
            'source' => 'declared',
            'installedAt' => null,
        ]], $result['plugins']);
    }

    public function testARecordOverridesWhatTheDeclarationWouldHaveSaid(): void
    {
        // A record only exists because somebody acted on that plugin. That
        // decision outranks the default the declaration carries.
        $this->registry->register($this->record('DeclaredFixture', enabled: false));

        $plugins = $this->call('plugins.list', [], null, [DeclaredFixturePlugin::class])['plugins'];

        self::assertCount(1, $plugins, 'It must be reported once, not twice.');
        self::assertFalse($plugins[0]['enabled']);
    }

    public function testDisablingADeclaredPluginCreatesItsRecord(): void
    {
        // The store starts empty and only records deviations, so the first
        // switch is what brings a record into existence.
        self::assertNull($this->registry->find('DeclaredFixture'));

        $this->call('plugins.disable', ['name' => 'DeclaredFixture'], null, [DeclaredFixturePlugin::class]);

        $record = $this->registry->find('DeclaredFixture');
        self::assertNotNull($record);
        self::assertFalse($record->enabled);
        self::assertSame('2.1.0', $record->version, 'The record is built from the metadata the class declares.');
    }

    public function testADeclaredPluginCanBeSwitchedBackOnAfterBeingSwitchedOff(): void
    {
        $declared = [DeclaredFixturePlugin::class];

        $this->call('plugins.disable', ['name' => 'DeclaredFixture'], null, $declared);
        $this->call('plugins.enable', ['name' => 'DeclaredFixture'], null, $declared);

        self::assertTrue($this->registry->find('DeclaredFixture')?->enabled);
    }

    public function testADeclaredPluginCannotBeRemovedFromASurface(): void
    {
        // Removing it would delete files the app's own code still names. The
        // message says what to do instead rather than just refusing.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('declared in this app\'s code');

        $this->call('plugins.remove', ['name' => 'DeclaredFixture'], $this->installer(), [DeclaredFixturePlugin::class]);
    }

    public function testADeclaredClassWithNoMetadataIsNotReported(): void
    {
        // Without metadata there is no name, and a plugin with no name cannot
        // be switched — listing it would offer a control that cannot work.
        /** @var list<class-string> $declared */
        $declared = [NamelessFixturePlugin::class, DeclaredFixturePlugin::class];

        self::assertSame(
            ['DeclaredFixture'],
            array_column($this->call('plugins.list', [], null, $declared)['plugins'], 'name'),
        );
    }

    public function testADeclaredClassThatDoesNotExistIsNotReported(): void
    {
        /** @var list<class-string> $declared */
        $declared = ['App\\Plugins\\Typo\\Typo'];

        self::assertSame(['plugins' => []], $this->call('plugins.list', [], null, $declared));
    }

    /**
     * A scripted installer that records what it was asked to do.
     */
    private function installer(
        ?PluginInstallResult $installResult = null,
        ?PluginInstallResult $updateResult = null,
        ?PluginRemoveResult $removeResult = null,
    ): PluginInstallerInterface {
        return new class ($installResult, $updateResult, $removeResult) implements PluginInstallerInterface {
            /** @var list<array{string, ?string}> */
            public array $updates = [];

            /** @var list<array{string, bool}> */
            public array $removals = [];

            public function __construct(
                private readonly ?PluginInstallResult $installResult,
                private readonly ?PluginInstallResult $updateResult,
                private readonly ?PluginRemoveResult $removeResult,
            ) {
            }

            public function require(string $source): PluginInstallResult
            {
                return $this->installResult ?? new PluginInstallResult(
                    success: true,
                    pluginName: 'MailPlugin',
                    version: '2.0.0',
                    source: 'github:acme/mail-plugin',
                    composerPackagesInstalled: ['acme/lib' => '^1.0'],
                    migrationsExecuted: 2,
                );
            }

            public function update(string $pluginName, ?string $targetVersion = null): PluginInstallResult
            {
                $this->updates[] = [$pluginName, $targetVersion];

                return $this->updateResult ?? new PluginInstallResult(
                    success: true,
                    pluginName: $pluginName,
                    version: '2.0.0',
                    source: 'github:acme/mail-plugin',
                );
            }

            public function resolve(string $source): DependencyResolution
            {
                return new DependencyResolution(resolvable: true);
            }

            public function remove(string $pluginName, bool $keepData = false): PluginRemoveResult
            {
                $this->removals[] = [$pluginName, $keepData];

                return $this->removeResult ?? PluginRemoveResult::success($pluginName, dataKept: $keepData);
            }
        };
    }
}

#[\Milpa\Attributes\PluginMetadata(version: '2.1.0', author: 'Acme', site: 'https://example.com', name: 'DeclaredFixture', type: 'Service')]
final class DeclaredFixturePlugin
{
}

/** A declared class carrying no metadata: it has no name to switch. */
final class NamelessFixturePlugin
{
}
