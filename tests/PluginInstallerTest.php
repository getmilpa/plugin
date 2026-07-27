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
use Milpa\Plugin\Contracts\PluginDownloaderInterface;
use Milpa\Plugin\Tests\Fixtures\FakeDownloader;
use Milpa\ValueObjects\SemanticVersion;

/**
 * PluginInstaller (v2, Ola 6c): the activation store, migrations, and
 * composer requires all go through ports — InMemoryPluginRegistry, the v2
 * PluginMigrationRunner, and a scripted ComposerRunnerInterface spy — with a
 * network-free source of plugins. Two deliberate behavior changes over the
 * legacy installer, both proven here: a composer failure ABORTS (no
 * file/registry change) instead of log-and-continue, and an invalid composer
 * package name (an option-injection vector) ABORTS naming the offender before
 * composer is ever invoked.
 */
final class PluginInstallerTest extends InstallerTestCase
{
    // =========================================================================
    // require()
    // =========================================================================

    public function testRequireHappyPathWithoutComposerDepsRegistersCopiesAndLocks(): void
    {
        $extractDir = $this->buildExtractDir('MailPlugin', '1.0.0');
        $installer = $this->makeInstaller(new FakeDownloader($extractDir, '1.0.0'), $this->composerSpy());

        $result = $installer->require('acme/mail-plugin');

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('MailPlugin', $result->pluginName);
        $this->assertSame('1.0.0', $result->version);
        $this->assertSame('github:acme/mail-plugin', $result->source);

        $record = $this->registry->find('MailPlugin');
        $this->assertNotNull($record);
        $this->assertTrue($record->installed);
        $this->assertFalse($record->enabled);
        $this->assertSame('github:acme/mail-plugin', $record->source);
        $this->assertSame('1.0.0', $record->installedVersion);

        $this->assertDirectoryExists($this->tmpRoot . '/plugins/MailPlugin');
        $this->assertFileExists($this->tmpRoot . '/plugins/MailPlugin/milpa.json');

        $lock = $this->lockManager->read();
        $this->assertNotNull($lock);
        $this->assertArrayHasKey('MailPlugin', $lock['plugins']);
        $this->assertSame('1.0.0', $lock['plugins']['MailPlugin']['version']);
    }

    public function testRequireWithInvalidPackageSpecAbortsNamingTheOffenderWithZeroComposerCalls(): void
    {
        $extractDir = $this->buildExtractDir('BadDepsPlugin', '1.0.0', ['--working-dir=/tmp' => '^1.0']);
        $composerSpy = $this->composerSpy();
        $installer = $this->makeInstaller(new FakeDownloader($extractDir), $composerSpy);

        $result = $installer->require('acme/bad-deps-plugin');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('--working-dir=/tmp', (string) $result->error);
        $this->assertStringContainsString('not a valid composer package name', (string) $result->error);
        $this->assertSame([], $composerSpy->calls);
        $this->assertNull($this->registry->find('BadDepsPlugin'));
        $this->assertDirectoryDoesNotExist($this->tmpRoot . '/plugins/BadDepsPlugin');
    }

    public function testRequireWithComposerFailureAbortsWithRunnerOutput(): void
    {
        $extractDir = $this->buildExtractDir('SdkPlugin', '1.0.0', ['acme/sdk' => '^2.0']);
        $failure = new ComposerResult(
            success: false,
            installed: [],
            failedPackage: 'acme/sdk:^2.0',
            output: "simulated composer failure\n",
        );
        $composerSpy = $this->composerSpy($failure);
        $installer = $this->makeInstaller(new FakeDownloader($extractDir), $composerSpy);

        $result = $installer->require('acme/sdk-plugin');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('acme/sdk:^2.0', (string) $result->error);
        $this->assertStringContainsString('simulated composer failure', (string) $result->error);
        $this->assertCount(1, $composerSpy->calls);
        $this->assertNull($this->registry->find('SdkPlugin'));
        $this->assertDirectoryDoesNotExist($this->tmpRoot . '/plugins/SdkPlugin');
    }

    public function testRequireOfAnAlreadyRegisteredNameFails(): void
    {
        $this->registry->register($this->makeRecord('DupPlugin', 'local'));

        $extractDir = $this->buildExtractDir('DupPlugin', '1.0.0');
        $installer = $this->makeInstaller(new FakeDownloader($extractDir), $this->composerSpy());

        $result = $installer->require('acme/dup-plugin');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('already installed', (string) $result->error);
    }

    // =========================================================================
    // remove()
    // =========================================================================

    public function testRemoveOfARegisteredRemotePluginUnregistersAndCleansDirWhileLocalRefuses(): void
    {
        $cleanupSpy = new class () implements PluginDownloaderInterface {
            /** @var list<string> */
            public array $cleaned = [];

            public function cleanup(string $path): void
            {
                $this->cleaned[] = $path;
            }

            /**
             * @return array{owner: string, repo: string, constraint: ?string}
             */
            public function parseSource(string $source): array
            {
                throw new \LogicException('remove() never reads a coordinate.');
            }

            public function resolveVersion(string $owner, string $repo, ?string $constraint = null): SemanticVersion
            {
                throw new \LogicException('remove() never resolves a version.');
            }

            public function download(string $owner, string $repo, SemanticVersion $version): string
            {
                throw new \LogicException('remove() never downloads.');
            }
        };
        $installer = $this->makeInstaller($cleanupSpy, $this->composerSpy());

        $this->registry->register($this->makeRecord('RemotePlugin', 'github:acme/remote-plugin'));
        $targetDir = $this->tmpRoot . '/plugins/RemotePlugin';
        mkdir($targetDir, 0755, true);

        $result = $installer->remove('RemotePlugin');

        $this->assertTrue($result->success);
        $this->assertFalse($result->dataKept);
        $this->assertNull($this->registry->find('RemotePlugin'));
        $this->assertContains($targetDir, $cleanupSpy->cleaned);

        $this->registry->register($this->makeRecord('LocalPlugin', 'local'));

        $localResult = $installer->remove('LocalPlugin');

        $this->assertFalse($localResult->success);
        $this->assertStringContainsString('installed locally', (string) $localResult->error);
        $this->assertNotNull($this->registry->find('LocalPlugin'));
    }

    // =========================================================================
    // update()
    // =========================================================================

    public function testUpdateOfNotInstalledAndLocalPluginsRefusesWithoutTouchingTheNetwork(): void
    {
        $forbidden = $this->forbiddenDownloader('network must not be reached by update() guard clauses');
        $installer = $this->makeInstaller($forbidden, $this->composerSpy());

        $ghostResult = $installer->update('GhostPlugin');
        $this->assertFalse($ghostResult->success);
        $this->assertStringContainsString('is not installed', (string) $ghostResult->error);

        $this->registry->register($this->makeRecord('LocalPlugin', 'local'));
        $localResult = $installer->update('LocalPlugin');
        $this->assertFalse($localResult->success);
        $this->assertStringContainsString('installed locally', (string) $localResult->error);
    }

    // =========================================================================
    // checkOutdated()
    // =========================================================================

    public function testCheckOutdatedWithOnlyLocalPluginsReturnsEmptyWithoutTouchingTheDownloader(): void
    {
        $forbidden = $this->forbiddenDownloader('network must not be reached when every installed plugin is local');
        $installer = $this->makeInstaller($forbidden, $this->composerSpy());

        $this->registry->register($this->makeRecord('LocalPlugin', 'local'));
        $this->registry->register($this->makeRecord('LocalPlugin2', null));

        $this->assertSame([], $installer->checkOutdated());
    }
}
