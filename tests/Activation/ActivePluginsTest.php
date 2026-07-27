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

namespace Milpa\Plugin\Tests\Activation;

use Milpa\Attributes\PluginMetadata;
use Milpa\Plugin\Activation\ActivePlugins;
use Milpa\Plugin\Contracts\PluginRecord;
use Milpa\Plugin\Registry\InMemoryPluginRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The decision that lets a panel switch plugins without writing PHP.
 *
 * A host declares what it has in code and stores what is switched on as state.
 * Everything here is about how those two combine — and above all about the
 * default, because getting it backwards would mean a developer adds a line to
 * the list and nothing happens until they also go and enable it somewhere else.
 */
final class ActivePluginsTest extends TestCase
{
    private InMemoryPluginRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new InMemoryPluginRegistry();
    }

    private function register(string $name, bool $enabled): void
    {
        $this->registry->register(new PluginRecord(
            name: $name,
            version: '1.0.0',
            author: 'Acme',
            site: 'https://example.com',
            type: 'Service',
            installed: true,
            enabled: $enabled,
        ));
    }

    public function testWhatIsDeclaredBootsWhenNothingHasEverBeenSwitched(): void
    {
        // The whole point of the default: a host that never touched activation
        // behaves exactly like one that only had a list.
        self::assertSame(
            [AlphaPlugin::class, BetaPlugin::class],
            ActivePlugins::resolve([AlphaPlugin::class, BetaPlugin::class], $this->registry),
        );
    }

    public function testTheStoreCanSwitchOffSomethingTheCodeDeclares(): void
    {
        $this->register('Beta', enabled: false);

        self::assertSame(
            [AlphaPlugin::class],
            ActivePlugins::resolve([AlphaPlugin::class, BetaPlugin::class], $this->registry),
        );
    }

    public function testAnEnabledRecordChangesNothingAboutADeclaredPlugin(): void
    {
        $this->register('Beta', enabled: true);

        self::assertSame(
            [AlphaPlugin::class, BetaPlugin::class],
            ActivePlugins::resolve([AlphaPlugin::class, BetaPlugin::class], $this->registry),
        );
    }

    public function testDeclarationOrderIsPreserved(): void
    {
        // Order is the host's stated boot order; the store has no opinion on
        // it and must not quietly reshuffle anything.
        self::assertSame(
            [BetaPlugin::class, AlphaPlugin::class],
            ActivePlugins::resolve([BetaPlugin::class, AlphaPlugin::class], $this->registry),
        );
    }

    public function testAPluginInstalledAtRuntimeBootsWithoutAnyoneDeclaringIt(): void
    {
        // This is what makes install-from-a-panel work at all: nobody edited
        // config, and the plugin still boots.
        $this->register('RuntimeInstalled', enabled: true);

        self::assertSame(
            [AlphaPlugin::class, \Milpa\Plugins\RuntimeInstalled\RuntimeInstalled::class],
            ActivePlugins::resolve([AlphaPlugin::class], $this->registry),
        );
    }

    public function testAnInstalledButDisabledPluginStaysOff(): void
    {
        // An install leaves the record disabled, so this is the state right
        // after installing: present, not running.
        $this->register('RuntimeInstalled', enabled: false);

        self::assertSame([AlphaPlugin::class], ActivePlugins::resolve([AlphaPlugin::class], $this->registry));
    }

    public function testARecordWhoseClassCannotBeLoadedIsSkippedRatherThanFatal(): void
    {
        // Installed but not autoloadable yet. Booting would fatal on a class
        // that is not there; refusing to boot anything would punish every
        // other plugin for one bad composer state.
        $this->register('NeverAutoloaded', enabled: true);

        self::assertSame([AlphaPlugin::class], ActivePlugins::resolve([AlphaPlugin::class], $this->registry));
    }

    public function testAPluginIsNeverListedTwiceWhenItIsBothDeclaredAndRecorded(): void
    {
        $this->register('Alpha', enabled: true);

        self::assertSame([AlphaPlugin::class], ActivePlugins::resolve([AlphaPlugin::class], $this->registry));
    }

    public function testAClassWithNoMetadataIsGovernedByTheDeclarationAlone(): void
    {
        // It cannot be matched to a record, so the store can never switch it
        // off — which is the honest outcome, not a silent one: without
        // metadata there is no name to switch.
        $this->register('Nameless', enabled: false);

        self::assertSame([NamelessPlugin::class], ActivePlugins::resolve([NamelessPlugin::class], $this->registry));
    }

    public function testADeclaredClassThatDoesNotExistIsStillHandedToTheKernel(): void
    {
        // Deciding it away here would swallow a typo in the host's own list.
        // The kernel already fails loudly on an unloadable plugin class, and
        // that message names the class.
        /** @var list<class-string> $declared */
        $declared = ['App\\Plugins\\Typo\\Typo'];

        self::assertSame($declared, ActivePlugins::resolve($declared, $this->registry));
    }

    public function testAnEmptyHostResolvesToAnEmptyList(): void
    {
        self::assertSame([], ActivePlugins::resolve([], $this->registry));
    }

    public function testTheFileBackedEntryPointWorksBeforeTheStateFileExists(): void
    {
        // A fresh host has no state file. Requiring one to exist would mean
        // every new project starts by creating an empty JSON document.
        $path = sys_get_temp_dir() . '/milpa_active_' . uniqid() . '/plugins.json';

        self::assertSame([AlphaPlugin::class], ActivePlugins::from([AlphaPlugin::class], $path));
        self::assertFileDoesNotExist($path, 'Reading activation state must not create it.');
    }

    public function testTheFileBackedEntryPointReadsWhatWasPersisted(): void
    {
        $dir = sys_get_temp_dir() . '/milpa_active_' . uniqid();
        mkdir($dir, 0755, true);
        $path = $dir . '/plugins.json';

        try {
            (new \Milpa\Plugin\Registry\FilePluginRegistry($path))->register(new PluginRecord(
                name: 'Beta',
                version: '1.0.0',
                author: 'Acme',
                site: 'https://example.com',
                type: 'Service',
                installed: true,
                enabled: false,
            ));

            self::assertSame([AlphaPlugin::class], ActivePlugins::from([AlphaPlugin::class, BetaPlugin::class], $path));
        } finally {
            @unlink($path);
            @rmdir($dir);
        }
    }
}

#[PluginMetadata(version: '1.0.0', author: 'Acme', site: 'https://example.com', name: 'Alpha', type: 'Service')]
final class AlphaPlugin
{
}

#[PluginMetadata(version: '1.0.0', author: 'Acme', site: 'https://example.com', name: 'Beta', type: 'Service')]
final class BetaPlugin
{
}

/** A plugin class that declares no metadata at all. */
final class NamelessPlugin
{
}
