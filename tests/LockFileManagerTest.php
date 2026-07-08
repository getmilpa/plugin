<?php

/**
 * This file is part of Milpa Plugin — the GitHub-native plugin distribution
 * core of the Milpa PHP framework.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/plugin
 */

declare(strict_types=1);

namespace Milpa\Plugin\Tests;

use Milpa\Plugin\LockFileManager;
use PHPUnit\Framework\TestCase;

final class LockFileManagerTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootPath = sys_get_temp_dir() . '/milpa_lock_' . uniqid();
        mkdir($this->rootPath, 0755, true);
    }

    protected function tearDown(): void
    {
        $lock = $this->rootPath . '/milpa.lock';
        if (file_exists($lock)) {
            unlink($lock);
        }
        rmdir($this->rootPath);
        parent::tearDown();
    }

    public function testGetPathAppendsMilpaLockToRoot(): void
    {
        $manager = new LockFileManager($this->rootPath);

        $this->assertSame($this->rootPath . '/milpa.lock', $manager->getPath());
    }

    public function testGetPathTrimsTrailingSlash(): void
    {
        $manager = new LockFileManager($this->rootPath . '/');

        $this->assertSame($this->rootPath . '/milpa.lock', $manager->getPath());
    }

    public function testExistsIsFalseBeforeGenerate(): void
    {
        $manager = new LockFileManager($this->rootPath);

        $this->assertFalse($manager->exists());
    }

    public function testReadReturnsNullWhenNoLockFile(): void
    {
        $manager = new LockFileManager($this->rootPath);

        $this->assertNull($manager->read());
    }

    public function testGetPluginLockReturnsNullWhenNoLockFile(): void
    {
        $manager = new LockFileManager($this->rootPath);

        $this->assertNull($manager->getPluginLock('MailPlugin'));
    }

    public function testGenerateThenReadRoundTrips(): void
    {
        $manager = new LockFileManager($this->rootPath);

        $manager->generate([
            ['name' => 'MailPlugin', 'version' => '1.2.3', 'source' => 'github:acme/mail-plugin'],
            ['name' => 'AuthPlugin', 'version' => '2.0.0'],
        ], milpaVersion: '2.1.0');

        $this->assertTrue($manager->exists());

        $lock = $manager->read();

        $this->assertNotNull($lock);
        $this->assertSame('2.1.0', $lock['milpa-version']);
        $this->assertArrayHasKey('generated-at', $lock);
        $this->assertArrayHasKey('content-hash', $lock);
        $this->assertSame(['AuthPlugin', 'MailPlugin'], array_keys($lock['plugins'])); // ksort()
        $this->assertSame('1.2.3', $lock['plugins']['MailPlugin']['version']);
        $this->assertSame('github:acme/mail-plugin', $lock['plugins']['MailPlugin']['source']);
        $this->assertSame('local', $lock['plugins']['AuthPlugin']['source']); // default
    }

    public function testGenerateOmitsComposerDepsKeyWhenEmpty(): void
    {
        $manager = new LockFileManager($this->rootPath);

        $manager->generate([['name' => 'MailPlugin', 'version' => '1.0.0']]);

        $lock = $manager->read();

        $this->assertArrayNotHasKey('composer-deps', $lock['plugins']['MailPlugin']);
    }

    public function testGenerateIncludesComposerDepsWhenPresent(): void
    {
        $manager = new LockFileManager($this->rootPath);

        $manager->generate([[
            'name' => 'MailPlugin',
            'version' => '1.0.0',
            'composerDeps' => ['symfony/mailer:^7.0'],
        ]]);

        $lock = $manager->read();

        $this->assertSame(['symfony/mailer:^7.0'], $lock['plugins']['MailPlugin']['composer-deps']);
    }

    public function testGetPluginLockReturnsInstalledPluginData(): void
    {
        $manager = new LockFileManager($this->rootPath);
        $manager->generate([['name' => 'MailPlugin', 'version' => '1.0.0']]);

        $pluginLock = $manager->getPluginLock('MailPlugin');

        $this->assertNotNull($pluginLock);
        $this->assertSame('1.0.0', $pluginLock['version']);
    }

    public function testGetPluginLockReturnsNullForUnknownPlugin(): void
    {
        $manager = new LockFileManager($this->rootPath);
        $manager->generate([['name' => 'MailPlugin', 'version' => '1.0.0']]);

        $this->assertNull($manager->getPluginLock('DoesNotExist'));
    }

    public function testVerifyReturnsTrueForFreshlyGeneratedLock(): void
    {
        $manager = new LockFileManager($this->rootPath);
        $manager->generate([['name' => 'MailPlugin', 'version' => '1.0.0']]);

        $this->assertTrue($manager->verify());
    }

    public function testVerifyReturnsFalseWhenNoLockFile(): void
    {
        $manager = new LockFileManager($this->rootPath);

        $this->assertFalse($manager->verify());
    }

    public function testVerifyDetectsTamperedContent(): void
    {
        $manager = new LockFileManager($this->rootPath);
        $manager->generate([['name' => 'MailPlugin', 'version' => '1.0.0']]);

        $lock = $manager->read();
        $lock['plugins']['MailPlugin']['version'] = '9.9.9'; // tamper without recomputing hash
        file_put_contents($manager->getPath(), json_encode($lock, JSON_THROW_ON_ERROR));

        $this->assertFalse($manager->verify());
    }
}
