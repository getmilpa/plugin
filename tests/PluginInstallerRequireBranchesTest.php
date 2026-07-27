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

namespace Milpa\Plugin\Tests;

use Milpa\Plugin\Contracts\ComposerResult;
use Milpa\Plugin\Tests\Fixtures\FakeDownloader;

/**
 * Every way require() can refuse, and the two things it does on the way in.
 *
 * The happy path and the composer guards live in {@see PluginInstallerTest};
 * what is here is the rest of the fan-out — the refusals a person installing
 * from a catalog will actually meet, each one checked for the same thing:
 * that it says what went wrong and leaves nothing half-installed behind.
 */
final class PluginInstallerRequireBranchesTest extends InstallerTestCase
{
    public function testRequireOfASourceWithNoManifestIsRefused(): void
    {
        // A repo that is not a Milpa plugin at all. The manifest is the only
        // thing that makes it one, so its absence is the first gate.
        $parent = sys_get_temp_dir() . '/milpa_installer_bare_' . uniqid();
        $bare = $parent . '/package';
        mkdir($bare, 0755, true);
        $this->extractDirs[] = $parent;

        $installer = $this->makeInstaller(new FakeDownloader($bare, '1.0.0'), $this->composerSpy());

        $result = $installer->require('acme/not-a-plugin');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('no milpa.json manifest', (string) $result->error);
        $this->assertSame('not-a-plugin', $result->pluginName, 'With no manifest there is no namespace to read a name from — the repo name is what is left.');
    }

    public function testRequireOfAPluginThatNeedsAnAbsentPluginNamesTheMissingOne(): void
    {
        $extract = $this->buildExtractDir('MailPlugin', '1.0.0', [], ['QueuePlugin' => '^1.0']);
        $installer = $this->makeInstaller(new FakeDownloader($extract, '1.0.0'), $this->composerSpy());

        $result = $installer->require('acme/mail-plugin');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Missing required plugins: QueuePlugin', (string) $result->error);
        $this->assertNull($this->registry->find('MailPlugin'));
        $this->assertFileDoesNotExist($this->tmpRoot . '/plugins/MailPlugin');
    }

    public function testRequireOfAPluginWhoseDependencyIsInstalledAtTheWrongVersionReportsTheConflict(): void
    {
        // The dependency is present, so it is not "missing" — it simply does
        // not satisfy the constraint. The two read very differently to whoever
        // has to fix it, and the installer keeps them apart.
        $this->registry->register($this->makeRecord('QueuePlugin', 'local'));
        $queueDir = $this->tmpRoot . '/plugins/QueuePlugin';
        mkdir($queueDir, 0755, true);
        copy($this->buildExtractDir('QueuePlugin', '1.0.0') . '/milpa.json', $queueDir . '/milpa.json');

        $extract = $this->buildExtractDir('MailPlugin', '1.0.0', [], ['QueuePlugin' => '^2.0']);
        $installer = $this->makeInstaller(new FakeDownloader($extract, '1.0.0'), $this->composerSpy());

        $result = $installer->require('acme/mail-plugin');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Dependency conflicts:', (string) $result->error);
        $this->assertStringContainsString('does not satisfy ^2.0', (string) $result->error);
        $this->assertNull($this->registry->find('MailPlugin'));
    }

    public function testRequireIntoADirectoryThatAlreadyExistsIsRefusedWithoutOverwritingIt(): void
    {
        // The registry does not know about it, but the directory is there —
        // an interrupted install, or something a person put there by hand.
        // Overwriting it would destroy whatever it holds.
        $squatter = $this->tmpRoot . '/plugins/MailPlugin';
        mkdir($squatter, 0755, true);
        file_put_contents($squatter . '/mine.txt', 'do not overwrite');

        $installer = $this->makeInstaller(new FakeDownloader($this->buildExtractDir('MailPlugin', '1.0.0'), '1.0.0'), $this->composerSpy());

        $result = $installer->require('acme/mail-plugin');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('Directory already exists: plugins/MailPlugin/', (string) $result->error);
        $this->assertSame('do not overwrite', file_get_contents($squatter . '/mine.txt'));
        $this->assertNull($this->registry->find('MailPlugin'));
    }

    public function testRequireStoresTheComposerPackagesItInstalledOnTheRecord(): void
    {
        $extract = $this->buildExtractDir('MailPlugin', '1.0.0', ['acme/lib' => '^1.0']);
        $installer = $this->makeInstaller(
            new FakeDownloader($extract, '1.0.0'),
            $this->composerSpy(new ComposerResult(success: true, installed: ['acme/lib' => '^1.0'], failedPackage: null, output: '')),
        );

        $result = $installer->require('acme/mail-plugin');

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(['acme/lib' => '^1.0'], $result->composerPackagesInstalled);
        $this->assertSame(['acme/lib' => '^1.0'], $this->registry->find('MailPlugin')?->composerDeps, 'What composer installed for a plugin has to be on its record, or removing the plugin cannot know what to undo.');
    }

    public function testRequireRunsThePluginsInstallHookWhenTheEntrypointDeclaresTheClass(): void
    {
        $name = 'HookPlugin';
        $marker = $this->tmpRoot . '/install-hook-ran';
        $entrypoint = <<<PHP
            <?php
            namespace Milpa\\Plugins\\{$name};
            class {$name} {
                public function __construct(public mixed \$container) {}
                public function install(): void { file_put_contents('{$marker}', 'ran'); }
            }
            PHP;

        $installer = $this->makeInstaller(
            new FakeDownloader($this->buildExtractDir($name, '1.0.0', [], [], $entrypoint), '1.0.0'),
            $this->composerSpy(),
        );

        $result = $installer->require('acme/hook-plugin');

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertFileExists($marker, "The plugin's own install() is the only chance it gets to set itself up.");
    }

    public function testAnInstallHookThatThrowsIsReportedButDoesNotUndoTheInstall(): void
    {
        // A plugin that breaks in its own install() is still installed: the
        // files are there and the registry knows it. Rolling the whole thing
        // back would leave the person with nothing to inspect or fix.
        $name = 'ThrowingPlugin';
        $entrypoint = <<<PHP
            <?php
            namespace Milpa\\Plugins\\{$name};
            class {$name} {
                public function __construct(public mixed \$container) {}
                public function install(): void { throw new \\RuntimeException('no database yet'); }
            }
            PHP;

        $installer = $this->makeInstaller(
            new FakeDownloader($this->buildExtractDir($name, '1.0.0', [], [], $entrypoint), '1.0.0'),
            $this->composerSpy(),
        );

        /** @var list<array{string, string}> $seen */
        $seen = [];
        $installer->setOutputCallback(static function (string $message, string $type) use (&$seen): void {
            $seen[] = [$message, $type];
        });

        $result = $installer->require('acme/throwing-plugin');

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertNotNull($this->registry->find($name));
        $this->assertContains(['Warning: install() failed: no database yet', 'warning'], $seen);
    }

    public function testRequireReportsAThrownFailureInsteadOfLettingItEscape(): void
    {
        $installer = $this->makeInstaller($this->forbiddenDownloader('github is unreachable'), $this->composerSpy());

        $result = $installer->require('acme/mail-plugin');

        $this->assertFalse($result->success);
        $this->assertSame('github is unreachable', $result->error);
        $this->assertSame('acme/mail-plugin', $result->source, 'A failure that happened before anything was read still echoes the coordinate that was asked for.');
    }
}
