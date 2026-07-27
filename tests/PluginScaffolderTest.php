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

use Milpa\Plugin\PluginScaffolder;
use PHPUnit\Framework\TestCase;

/**
 * First test coverage for the scaffolder in repo history (Ola 6c T4). The
 * package scaffolder now generates milpa.json through
 * {@see \Milpa\Plugin\PluginManifest::generateFromMetadata()} — the single
 * manifest authority — instead of hand-assembling the array, with one
 * deliberate override: the vendor name keeps the scaffolder's kebab-case
 * instead of the generator's plain-strtolower default.
 */
final class PluginScaffolderTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRoot = sys_get_temp_dir() . '/milpa_scaffolder_' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpRoot);
        parent::tearDown();
    }

    public function testScaffoldHappyPathGeneratesDirectoriesAndFiles(): void
    {
        $scaffolder = new PluginScaffolder($this->tmpRoot);

        $result = $scaffolder->scaffold('MailPlugin', 'Service', 'Acme');

        $pluginDir = $this->tmpRoot . '/MailPlugin';
        self::assertSame($pluginDir, $result['path']);
        self::assertSame(['milpa.json', 'MailPlugin.php'], $result['files']);

        foreach ([
            $pluginDir,
            $pluginDir . '/Controllers',
            $pluginDir . '/Services',
            $pluginDir . '/Entities',
            $pluginDir . '/Commands',
            $pluginDir . '/Interfaces',
            $pluginDir . '/Middleware',
            $pluginDir . '/Migrations',
            $pluginDir . '/Records',
            $pluginDir . '/Resources/views',
        ] as $dir) {
            self::assertDirectoryExists($dir);
        }

        self::assertFileExists($pluginDir . '/milpa.json');
        self::assertFileExists($pluginDir . '/MailPlugin.php');

        $manifest = json_decode((string) file_get_contents($pluginDir . '/milpa.json'), true, 512, JSON_THROW_ON_ERROR);

        // Kebab preserved: the scaffolder's own toKebabCase() override survives
        // the generator's plain-strtolower default ("MailPlugin" would give
        // "milpa/mailplugin" otherwise).
        self::assertSame('milpa/mail-plugin', $manifest['name']);
        self::assertSame(['provides' => [], 'requires' => [], 'suggests' => []], $manifest['contracts']);
        self::assertSame('MailPlugin.php', $manifest['entrypoint']);
        self::assertSame('Milpa\\Plugins\\MailPlugin', $manifest['namespace']);

        $pluginClass = (string) file_get_contents($pluginDir . '/MailPlugin.php');
        self::assertStringContainsString('use Milpa\\Plugin\\PluginBase;', $pluginClass);
        self::assertStringContainsString('#[PluginMetadata(', $pluginClass);
        self::assertStringContainsString('parent::__construct(', $pluginClass);
    }

    public function testScaffoldNormalizesNameWithoutPluginSuffix(): void
    {
        $scaffolder = new PluginScaffolder($this->tmpRoot);

        $result = $scaffolder->scaffold('Mail');

        $pluginDir = $this->tmpRoot . '/MailPlugin';
        self::assertSame($pluginDir, $result['path']);
        self::assertDirectoryExists($pluginDir);
        self::assertFileExists($pluginDir . '/MailPlugin.php');
    }

    public function testScaffoldThrowsWhenPluginDirectoryAlreadyExists(): void
    {
        mkdir($this->tmpRoot . '/MailPlugin', 0755, true);
        $scaffolder = new PluginScaffolder($this->tmpRoot);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Plugin directory already exists');

        $scaffolder->scaffold('MailPlugin');
    }

    // Legacy parity check removed in 6c T7 with the legacy scaffolder itself; the absolute pins above ARE the contract now.

    private function removeDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
