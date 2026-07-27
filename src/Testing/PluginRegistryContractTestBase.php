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

namespace Milpa\Plugin\Testing;

use Milpa\Plugin\Contracts\PluginRecord;
use Milpa\Plugin\Contracts\PluginRegistryInterface;
use PHPUnit\Framework\TestCase;

/**
 * The registry contract, executable: every implementation (in-memory, file,
 * and the host's database adapter) must pass exactly these behaviors.
 */
abstract class PluginRegistryContractTestBase extends TestCase
{
    abstract protected function makeRegistry(): PluginRegistryInterface;

    protected function record(string $name, bool $installed = true, bool $enabled = false): PluginRecord
    {
        return new PluginRecord(
            name: $name,
            version: '1.0.0',
            author: 'Acme',
            site: 'https://example.com',
            type: 'Service',
            installed: $installed,
            enabled: $enabled,
        );
    }

    /** find() returns null for an unknown name and the full record once registered. */
    public function testFindReturnsNullWhenUnknownAndTheRecordOnceRegistered(): void
    {
        $registry = $this->makeRegistry();

        $this->assertNull($registry->find('MailPlugin'));

        $registry->register($this->record('MailPlugin'));

        $found = $registry->find('MailPlugin');
        $this->assertNotNull($found);
        $this->assertSame('MailPlugin', $found->name);
        $this->assertTrue($found->installed);
        $this->assertFalse($found->enabled);
    }

    /** register() refuses a duplicate plugin name. */
    public function testRegisterRefusesADuplicateName(): void
    {
        $registry = $this->makeRegistry();
        $registry->register($this->record('MailPlugin'));

        $this->expectException(\RuntimeException::class);
        $registry->register($this->record('MailPlugin'));
    }

    /** enabledNames() lists only the plugins whose enabled flag is on. */
    public function testEnabledNamesListsOnlyEnabledPlugins(): void
    {
        $registry = $this->makeRegistry();
        $registry->register($this->record('OnPlugin', enabled: true));
        $registry->register($this->record('OffPlugin', enabled: false));

        $this->assertSame(['OnPlugin'], $registry->enabledNames());
    }

    /** installed() keeps every registered record; installedAndEnabled() requires both flags. */
    public function testInstalledAndEnabledFiltersBothFlags(): void
    {
        $registry = $this->makeRegistry();
        $registry->register($this->record('BothPlugin', installed: true, enabled: true));
        $registry->register($this->record('OnlyInstalledPlugin', installed: true, enabled: false));

        $this->assertCount(2, $registry->installed());
        $both = $registry->installedAndEnabled();
        $this->assertCount(1, $both);
        $this->assertSame('BothPlugin', $both[0]->name);
    }

    /** setEnabled() flips the flag in place and throws for an unknown name. */
    public function testSetEnabledFlipsTheFlagAndThrowsOnUnknown(): void
    {
        $registry = $this->makeRegistry();
        $registry->register($this->record('MailPlugin', enabled: false));

        $registry->setEnabled('MailPlugin', true);
        $this->assertSame(['MailPlugin'], $registry->enabledNames());

        $this->expectException(\RuntimeException::class);
        $registry->setEnabled('GhostPlugin', true);
    }

    /** save() overwrites an existing record and throws for an unknown name. */
    public function testSaveOverwritesAndThrowsOnUnknown(): void
    {
        $registry = $this->makeRegistry();
        $registry->register($this->record('MailPlugin'));

        $updated = new PluginRecord(
            name: 'MailPlugin',
            version: '2.0.0',
            author: 'Acme',
            site: 'https://example.com',
            type: 'Service',
            installed: true,
            enabled: true,
            source: 'github:acme/mail-plugin',
        );
        $registry->save($updated);

        $found = $registry->find('MailPlugin');
        $this->assertNotNull($found);
        $this->assertSame('2.0.0', $found->version);
        $this->assertSame('github:acme/mail-plugin', $found->source);

        $this->expectException(\RuntimeException::class);
        $registry->save($this->record('GhostPlugin'));
    }

    /** unregister() removes the record and is a no-op the second time. */
    public function testUnregisterRemovesAndIsIdempotent(): void
    {
        $registry = $this->makeRegistry();
        $registry->register($this->record('MailPlugin'));

        $registry->unregister('MailPlugin');
        $this->assertNull($registry->find('MailPlugin'));

        $registry->unregister('MailPlugin'); // no-op, no exception
        $this->assertSame([], $registry->installed());
    }
}
