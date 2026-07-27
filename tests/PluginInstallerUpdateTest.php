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
 * The other three long paths through the installer: update(), resolve(), and
 * checkOutdated().
 *
 * update() is the one that matters most and the one nobody had exercised: it
 * is the only path that DELETES a directory a user already has. Everything
 * here is about what survives that deletion and what refuses to start it —
 * a downgrade, a new version with no manifest, a composer failure, a Records/
 * directory holding data the plugin wrote.
 */
final class PluginInstallerUpdateTest extends InstallerTestCase
{
    /**
     * Registers a plugin as remotely installed at $version, with its directory
     * and manifest already on disk — the state update() expects to find.
     */
    private function installedRemotely(string $name, string $version = '1.0.0'): string
    {
        $record = $this->makeRecord($name, "github:acme/{$name}");
        $this->registry->register($record);

        $dir = $this->tmpRoot . '/plugins/' . $name;
        mkdir($dir, 0755, true);
        copy($this->buildExtractDir($name, $version) . '/milpa.json', $dir . '/milpa.json');

        return $dir;
    }

    // =========================================================================
    // update() — what refuses to start
    // =========================================================================

    public function testUpdateToTheVersionAlreadyInstalledSucceedsWithoutTouchingTheDirectory(): void
    {
        // "Already at latest" is a success, not a failure: nothing was wrong
        // and nothing needed doing. Reporting it as an error would make every
        // no-op update look like a broken one.
        $dir = $this->installedRemotely('MailPlugin', '1.0.0');
        file_put_contents($dir . '/marker.txt', 'untouched');
        $installer = $this->makeInstaller(new FakeDownloader($this->buildExtractDir('MailPlugin', '1.0.0'), '1.0.0'), $this->composerSpy());

        $result = $installer->update('MailPlugin');

        $this->assertTrue($result->success);
        $this->assertStringContainsString('Already at latest version v1.0.0', (string) $result->error);
        $this->assertFileExists($dir . '/marker.txt');
    }

    public function testUpdateToAnOlderVersionIsRefusedBeforeAnythingIsDownloaded(): void
    {
        $dir = $this->installedRemotely('MailPlugin', '2.0.0');
        $this->registry->save($this->makeRecordAtVersion('MailPlugin', '2.0.0'));
        file_put_contents($dir . '/marker.txt', 'untouched');
        $installer = $this->makeInstaller(new FakeDownloader($this->buildExtractDir('MailPlugin', '1.0.0'), '1.0.0'), $this->composerSpy());

        $result = $installer->update('MailPlugin');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('older than installed v2.0.0', (string) $result->error);
        $this->assertFileExists($dir . '/marker.txt', 'A refused downgrade must not have deleted the directory.');
    }

    public function testUpdateToAVersionWithNoManifestAbortsAndLeavesTheInstalledOneInPlace(): void
    {
        $dir = $this->installedRemotely('MailPlugin', '1.0.0');
        file_put_contents($dir . '/marker.txt', 'untouched');

        $emptyParent = sys_get_temp_dir() . '/milpa_installer_empty_' . uniqid();
        $empty = $emptyParent . '/package';
        mkdir($empty, 0755, true);
        $this->extractDirs[] = $emptyParent;

        $installer = $this->makeInstaller(new FakeDownloader($empty, '2.0.0'), $this->composerSpy());

        $result = $installer->update('MailPlugin');

        $this->assertFalse($result->success);
        $this->assertStringContainsString('no milpa.json manifest', (string) $result->error);
        $this->assertFileExists($dir . '/marker.txt');
        $this->assertSame('1.0.0', $this->registry->find('MailPlugin')?->installedVersion);
    }

    public function testUpdateWithAnInvalidComposerPackageAbortsNamingTheOffenderWithZeroComposerCalls(): void
    {
        $this->installedRemotely('MailPlugin', '1.0.0');
        $extract = $this->buildExtractDir('MailPlugin', '2.0.0', ['--evil/pkg' => '^1.0']);
        $composer = $this->composerSpy();
        $installer = $this->makeInstaller(new FakeDownloader($extract, '2.0.0'), $composer);

        $result = $installer->update('MailPlugin');

        $this->assertFalse($result->success);
        $this->assertStringContainsString("Refusing composer dependency '--evil/pkg'", (string) $result->error);
        $this->assertStringContainsString('option-injection guard', (string) $result->error);
        $this->assertSame([], $composer->calls, 'The guard must fire before composer is ever invoked.');
        $this->assertSame('1.0.0', $this->registry->find('MailPlugin')?->installedVersion);
    }

    public function testUpdateWithAFailingComposerAbortsBeforeTheDirectoryIsReplaced(): void
    {
        $dir = $this->installedRemotely('MailPlugin', '1.0.0');
        file_put_contents($dir . '/marker.txt', 'untouched');
        $extract = $this->buildExtractDir('MailPlugin', '2.0.0', ['acme/lib' => '^1.0']);
        $installer = $this->makeInstaller(
            new FakeDownloader($extract, '2.0.0'),
            $this->composerSpy(new ComposerResult(success: false, installed: [], failedPackage: 'acme/lib', output: 'conflict with php 8.3')),
        );

        $result = $installer->update('MailPlugin');

        $this->assertFalse($result->success);
        $this->assertStringContainsString("composer require failed for 'acme/lib'", (string) $result->error);
        $this->assertStringContainsString('conflict with php 8.3', (string) $result->error);
        $this->assertFileExists($dir . '/marker.txt', 'An aborted update must leave the installed version intact.');
        $this->assertSame('1.0.0', $this->registry->find('MailPlugin')?->installedVersion);
    }

    // =========================================================================
    // update() — what it does when it goes through
    // =========================================================================

    public function testUpdateReplacesTheDirectoryAndMovesTheRecordToTheNewVersion(): void
    {
        $dir = $this->installedRemotely('MailPlugin', '1.0.0');
        file_put_contents($dir . '/stale.txt', 'from the old version');
        $extract = $this->buildExtractDir('MailPlugin', '2.0.0');
        $installer = $this->makeInstaller(new FakeDownloader($extract, '2.0.0'), $this->composerSpy());

        $result = $installer->update('MailPlugin');

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame('2.0.0', $result->version);
        $this->assertFileDoesNotExist($dir . '/stale.txt', 'The old directory is replaced, not merged over.');
        $this->assertFileExists($dir . '/MailPlugin.php');

        $record = $this->registry->find('MailPlugin');
        $this->assertSame('2.0.0', $record?->installedVersion);
        $this->assertSame('github:acme/MailPlugin', $record?->source, 'The source a plugin came from survives its updates.');
    }

    public function testUpdatePreservesTheRecordsDirectoryAcrossTheReplacement(): void
    {
        // Records/ is where a plugin keeps what it wrote. Deleting the plugin
        // directory to install the new version would take the user's data with
        // it — this is the one thing the replacement must carry across.
        $dir = $this->installedRemotely('MailPlugin', '1.0.0');
        mkdir($dir . '/Records', 0755, true);
        file_put_contents($dir . '/Records/data.json', '{"kept":true}');

        $installer = $this->makeInstaller(new FakeDownloader($this->buildExtractDir('MailPlugin', '2.0.0'), '2.0.0'), $this->composerSpy());

        $result = $installer->update('MailPlugin');

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertFileExists($dir . '/Records/data.json');
        $this->assertSame('{"kept":true}', file_get_contents($dir . '/Records/data.json'));
    }

    public function testUpdateOfAPluginWhoseDirectoryIsMissingStillLandsTheNewVersion(): void
    {
        // The registry says installed but the directory is gone — a half-broken
        // install. update() should repair it rather than refuse.
        $this->registry->register($this->makeRecord('MailPlugin', 'github:acme/MailPlugin'));
        $installer = $this->makeInstaller(new FakeDownloader($this->buildExtractDir('MailPlugin', '2.0.0'), '2.0.0'), $this->composerSpy());

        $result = $installer->update('MailPlugin');

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertFileExists($this->tmpRoot . '/plugins/MailPlugin/milpa.json');
    }

    public function testUpdateRecordsTheComposerPackagesItInstalledOnTopOfTheOldOnes(): void
    {
        $this->installedRemotely('MailPlugin', '1.0.0');
        $extract = $this->buildExtractDir('MailPlugin', '2.0.0', ['acme/lib' => '^2.0']);
        $installer = $this->makeInstaller(
            new FakeDownloader($extract, '2.0.0'),
            $this->composerSpy(new ComposerResult(success: true, installed: ['acme/lib' => '^2.0'], failedPackage: null, output: '')),
        );

        $result = $installer->update('MailPlugin');

        $this->assertTrue($result->success, (string) $result->error);
        $this->assertSame(['acme/lib' => '^2.0'], $result->composerPackagesInstalled);
        $this->assertSame(['acme/lib' => '^2.0'], $this->registry->find('MailPlugin')?->composerDeps);
    }

    public function testUpdateReportsAThrownFailureInsteadOfLettingItEscape(): void
    {
        // A caller updating from a UI gets a result object, never an exception
        // travelling up from the network.
        $this->installedRemotely('MailPlugin', '1.0.0');
        $installer = $this->makeInstaller($this->forbiddenDownloader('github is unreachable'), $this->composerSpy());

        $result = $installer->update('MailPlugin');

        $this->assertFalse($result->success);
        $this->assertSame('github is unreachable', $result->error);
    }

    // =========================================================================
    // resolve() — a dry run that must not leave anything behind
    // =========================================================================

    public function testResolveReportsDependenciesWithoutInstallingAnything(): void
    {
        $installer = $this->makeInstaller(new FakeDownloader($this->buildExtractDir('MailPlugin', '1.0.0', ['acme/lib' => '^1.0']), '1.0.0'), $this->composerSpy());

        $resolution = $installer->resolve('acme/mail-plugin');

        $this->assertTrue($resolution->resolvable, implode(', ', $resolution->conflicts));
        $this->assertSame(['acme/lib' => '^1.0'], $resolution->composerPackages);
        $this->assertNull($this->registry->find('MailPlugin'), 'A dry run registers nothing.');
        $this->assertFileDoesNotExist($this->tmpRoot . '/plugins/MailPlugin', 'A dry run copies nothing.');
    }

    public function testResolveOfASourceWithNoManifestIsUnresolvableRatherThanFatal(): void
    {
        $emptyParent = sys_get_temp_dir() . '/milpa_installer_empty_' . uniqid();
        $empty = $emptyParent . '/package';
        mkdir($empty, 0755, true);
        $this->extractDirs[] = $emptyParent;
        $installer = $this->makeInstaller(new FakeDownloader($empty, '1.0.0'), $this->composerSpy());

        $resolution = $installer->resolve('acme/mail-plugin');

        $this->assertFalse($resolution->resolvable);
        $this->assertStringContainsString('no milpa.json manifest', $resolution->conflicts[0]);
    }

    public function testResolveTurnsAThrownFailureIntoAConflictLine(): void
    {
        $installer = $this->makeInstaller($this->forbiddenDownloader('no such repository'), $this->composerSpy());

        $resolution = $installer->resolve('acme/ghost');

        $this->assertFalse($resolution->resolvable);
        $this->assertSame(['no such repository'], $resolution->conflicts);
    }

    // =========================================================================
    // checkOutdated()
    // =========================================================================

    public function testCheckOutdatedListsOnlyThePluginsWithANewerVersionUpstream(): void
    {
        $this->registry->register($this->makeRecord('MailPlugin', 'github:acme/MailPlugin'));
        $this->registry->register($this->makeRecordAtVersion('CachePlugin', '3.0.0', 'github:acme/CachePlugin'));
        $this->registry->register($this->makeRecord('LocalPlugin', 'local'));

        $installer = $this->makeInstaller(new FakeDownloader($this->buildExtractDir('Any'), '2.0.0'), $this->composerSpy());

        $outdated = $installer->checkOutdated();

        $this->assertCount(1, $outdated, 'Only the one behind upstream is listed; the ahead one and the local one are not.');
        $this->assertSame('MailPlugin', $outdated[0]['name']);
        $this->assertSame('1.0.0', $outdated[0]['current']);
        $this->assertSame('2.0.0', $outdated[0]['latest']);
        $this->assertSame('github:acme/MailPlugin', $outdated[0]['source']);
    }

    public function testCheckOutdatedSkipsThePluginsItCannotReachInsteadOfFailingTheWholeReport(): void
    {
        // One unreachable repo must not cost the user the report on all the
        // others — but with a single plugin there is nothing else to salvage,
        // so the visible result is simply an empty list, not an exception.
        $this->registry->register($this->makeRecord('MailPlugin', 'github:acme/MailPlugin'));
        $installer = $this->makeInstaller($this->forbiddenDownloader('github is unreachable'), $this->composerSpy());

        $this->assertSame([], $installer->checkOutdated());
    }

    // =========================================================================
    // the progress callback
    // =========================================================================

    public function testTheOutputCallbackReceivesTheStepsOfAnUpdate(): void
    {
        $this->installedRemotely('MailPlugin', '1.0.0');
        $installer = $this->makeInstaller(new FakeDownloader($this->buildExtractDir('MailPlugin', '2.0.0'), '2.0.0'), $this->composerSpy());

        /** @var list<array{string, string}> $seen */
        $seen = [];
        $installer->setOutputCallback(static function (string $message, string $type) use (&$seen): void {
            $seen[] = [$message, $type];
        });

        $installer->update('MailPlugin');

        $messages = array_column($seen, 0);
        $this->assertContains('Checking updates for MailPlugin...', $messages);
        $this->assertContains('Updating MailPlugin: v1.0.0 -> v2.0.0', $messages);
    }

    /**
     * A registered record pinned to a specific installed version.
     */
    private function makeRecordAtVersion(string $name, string $version, string $source = 'github:acme/MailPlugin'): \Milpa\Plugin\Contracts\PluginRecord
    {
        $base = $this->makeRecord($name, $source);

        return new \Milpa\Plugin\Contracts\PluginRecord(
            name: $base->name,
            version: $version,
            author: $base->author,
            site: $base->site,
            type: $base->type,
            installed: true,
            enabled: true,
            source: $source,
            installedVersion: $version,
        );
    }
}
