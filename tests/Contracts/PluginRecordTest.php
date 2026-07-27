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

namespace Milpa\Plugin\Tests\Contracts;

use Milpa\Plugin\Contracts\PluginRecord;
use PHPUnit\Framework\TestCase;

/**
 * PluginRecord is the registry's currency: a readonly snapshot of one plugin's
 * registration state, shaped after the exact fields the host consumes today.
 */
final class PluginRecordTest extends TestCase
{
    public function testCarriesAllFieldsAndDefaultsOptionalsToNull(): void
    {
        $record = new PluginRecord(
            name: 'MailPlugin',
            version: '1.2.0',
            author: 'Acme',
            site: 'https://example.com',
            type: 'Service',
            installed: true,
            enabled: false,
        );

        $this->assertSame('MailPlugin', $record->name);
        $this->assertSame('1.2.0', $record->version);
        $this->assertTrue($record->installed);
        $this->assertFalse($record->enabled);
        $this->assertNull($record->source);
        $this->assertNull($record->installedVersion);
        $this->assertNull($record->installedAt);
        $this->assertNull($record->composerDeps);
    }

    public function testCarriesRemoteInstallFields(): void
    {
        $at = new \DateTimeImmutable('2026-07-20T12:00:00+00:00');
        $record = new PluginRecord(
            name: 'MailPlugin',
            version: '1.2.0',
            author: 'Acme',
            site: 'https://example.com',
            type: 'Service',
            installed: true,
            enabled: true,
            source: 'github:acme/mail-plugin',
            installedVersion: '1.2.0',
            installedAt: $at,
            composerDeps: ['acme/sdk:^2.0'],
        );

        $this->assertSame('github:acme/mail-plugin', $record->source);
        $this->assertSame($at, $record->installedAt);
        $this->assertSame(['acme/sdk:^2.0'], $record->composerDeps);
    }

    public function testWithEnabledReplacesOnlyTheFlag(): void
    {
        $at = new \DateTimeImmutable('2026-07-20T12:00:00+00:00');
        $record = new PluginRecord(
            name: 'MailPlugin',
            version: '1.2.0',
            author: 'Acme',
            site: 'https://example.com',
            type: 'Service',
            installed: true,
            enabled: false,
            source: 'github:acme/mail-plugin',
            installedVersion: '1.2.0',
            installedAt: $at,
            composerDeps: ['acme/sdk:^2.0'],
        );

        $flipped = $record->withEnabled(true);

        $this->assertTrue($flipped->enabled);
        $this->assertFalse($record->enabled);
        $this->assertSame('MailPlugin', $flipped->name);
        $this->assertSame($at, $flipped->installedAt);
        $this->assertSame(['acme/sdk:^2.0'], $flipped->composerDeps);
    }
}
