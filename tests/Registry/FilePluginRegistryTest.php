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

namespace Milpa\Plugin\Tests\Registry;

use Milpa\Plugin\Contracts\PluginRegistryInterface;
use Milpa\Plugin\Registry\FilePluginRegistry;
use Milpa\Plugin\Testing\PluginRegistryContractTestBase;

final class FilePluginRegistryTest extends PluginRegistryContractTestBase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir() . '/milpa_file_registry_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->file)) {
            unlink($this->file);
        }
        parent::tearDown();
    }

    protected function makeRegistry(): PluginRegistryInterface
    {
        return new FilePluginRegistry($this->file);
    }

    public function testStateSurvivesAFreshInstanceRoundTrip(): void
    {
        $first = new FilePluginRegistry($this->file);
        $first->register($this->record('MailPlugin', enabled: true));

        $second = new FilePluginRegistry($this->file);

        $this->assertSame(['MailPlugin'], $second->enabledNames());
        $this->assertSame('1.0.0', $second->find('MailPlugin')?->version);
    }

    public function testAMissingFileMeansAnEmptyRegistry(): void
    {
        $registry = new FilePluginRegistry($this->file);

        $this->assertSame([], $registry->enabledNames());
        $this->assertSame([], $registry->installed());
    }

    public function testCorruptJsonFailsLoudly(): void
    {
        file_put_contents($this->file, '{not json');

        $this->expectException(\RuntimeException::class);
        (new FilePluginRegistry($this->file))->installed();
    }

    public function testEnabledNamesDegradesToEmptyOnCorruptJson(): void
    {
        file_put_contents($this->file, '{not json');

        $this->assertSame([], (new FilePluginRegistry($this->file))->enabledNames());
    }
}
