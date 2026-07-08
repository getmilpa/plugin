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

namespace Milpa\Plugin\Tests\Capabilities;

use Milpa\Plugin\PluginManifest;
use Milpa\ValueObjects\Capability\CapabilityProvision;
use Milpa\ValueObjects\Capability\CapabilityRequirement;
use Milpa\ValueObjects\Capability\CapabilitySuggestion;
use PHPUnit\Framework\TestCase;

/**
 * D7 wiring seam (T087) — PluginManifest must surface TYPED capability records,
 * accepting both the canonical `capabilities.*` key and the legacy `contracts.*`
 * key, and both the record form and the legacy bare-FQCN string form.
 */
final class PluginManifestCapabilitiesTest extends TestCase
{
    /**
     * @param array<string, mixed> $extra
     */
    private function manifest(array $extra): PluginManifest
    {
        return PluginManifest::fromArray(array_merge([
            'name' => 'vendor/pkg',
            'version' => '1.0.0',
            'entrypoint' => 'Plugin.php',
            'namespace' => 'Vendor\\Pkg',
        ], $extra));
    }

    public function testProvidedCapabilitiesParsesLegacyBareFqcnFromContracts(): void
    {
        $m = $this->manifest(['contracts' => ['provides' => [
            'Vendor\\Pkg\\Interfaces\\FooInterface',
            'Vendor\\Pkg\\Interfaces\\BarInterface',
        ]]]);

        $caps = $m->getProvidedCapabilities();

        $this->assertCount(2, $caps);
        $this->assertInstanceOf(CapabilityProvision::class, $caps[0]);
        $this->assertSame('Vendor\\Pkg\\Interfaces\\FooInterface', $caps[0]->interface);
        $this->assertSame('0.0.0', $caps[0]->contractVersion);
    }

    public function testProvidedCapabilitiesParsesRecordForm(): void
    {
        $m = $this->manifest(['capabilities' => ['provides' => [[
            'id' => 'vendor.foo',
            'interface' => 'Vendor\\Pkg\\FooInterface',
            'contractVersion' => '2.0.0',
            'service' => 'Vendor\\Pkg\\Foo',
            'priority' => 50,
        ]]]]);

        $caps = $m->getProvidedCapabilities();

        $this->assertCount(1, $caps);
        $this->assertSame('vendor.foo', $caps[0]->id);
        $this->assertSame('2.0.0', $caps[0]->contractVersion);
        $this->assertSame('Vendor\\Pkg\\Foo', $caps[0]->service);
        $this->assertSame(50, $caps[0]->priority);
    }

    public function testCanonicalCapabilitiesKeyTakesPrecedenceOverContracts(): void
    {
        $m = $this->manifest([
            'capabilities' => ['provides' => [[
                'id' => 'canonical',
                'interface' => 'X',
                'contractVersion' => '1.0.0',
            ]]],
            'contracts' => ['provides' => ['Legacy\\Iface']],
        ]);

        $caps = $m->getProvidedCapabilities();

        $this->assertCount(1, $caps);
        $this->assertSame('canonical', $caps[0]->id);
    }

    public function testRequiredAndSuggestedTypedGetters(): void
    {
        $m = $this->manifest(['contracts' => [
            'requires' => [[
                'id' => 'r',
                'interface' => 'R',
                'constraint' => '^1.0',
                'oneOf' => ['p1', 'p2'],
            ]],
            'suggests' => ['Legacy\\Sugg'],
        ]]);

        $req = $m->getRequiredCapabilities();
        $this->assertInstanceOf(CapabilityRequirement::class, $req[0]);
        $this->assertSame('^1.0', $req[0]->constraint);
        $this->assertSame(['p1', 'p2'], $req[0]->oneOf);

        $sug = $m->getSuggestedCapabilities();
        $this->assertInstanceOf(CapabilitySuggestion::class, $sug[0]);
        $this->assertSame('Legacy\\Sugg', $sug[0]->interface);
        $this->assertSame('*', $sug[0]->constraint);
    }

    public function testEmptyWhenNoCapabilitiesDeclared(): void
    {
        $m = $this->manifest([]);

        $this->assertSame([], $m->getProvidedCapabilities());
        $this->assertSame([], $m->getRequiredCapabilities());
        $this->assertSame([], $m->getSuggestedCapabilities());
    }
}
