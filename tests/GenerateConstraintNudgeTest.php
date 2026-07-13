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

use Milpa\Plugin\PluginManifest;
use PHPUnit\Framework\TestCase;

/**
 * The T2-review nudge (adjudication 4, recommended for T3): an omitted `constraint` on a rich
 * `requires`/`suggests` record still normalizes to the spec's any-version `*` (the schema requires
 * the key — normalization, not invention), but the generator now says so through the existing
 * `$warnings` channel, so a migration that MEANT to pin a contract range hears about the silent `*`.
 * A record that declares its constraint — even an explicit `*` — generates without a word.
 */
final class GenerateConstraintNudgeTest extends TestCase
{
    private const REQUIRES_NO_CONSTRAINT = [
        'id' => 'crm.fixture.storage.v1',
        'interface' => 'Milpa\\Plugin\\Tests\\Fixtures\\FixtureMailerInterface',
    ];

    public function testOmittedRequiresConstraintEmitsTheNudgeAndStillNormalizesToAnyVersion(): void
    {
        $warnings = [];
        $manifest = PluginManifest::generateFromMetadata(
            $this->metadata(requires: [self::REQUIRES_NO_CONSTRAINT]),
            'Milpa\\Plugins\\FixturePlugin',
            'FixturePlugin.php',
            $warnings,
        );

        $this->assertSame('*', $manifest['capabilities']['requires'][0]['constraint']);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('crm.fixture.storage.v1', $warnings[0]);
        $this->assertStringContainsString('constraint', $warnings[0]);
        $this->assertStringContainsString('*', $warnings[0]);
    }

    public function testOmittedSuggestsConstraintEmitsTheNudge(): void
    {
        $warnings = [];
        PluginManifest::generateFromMetadata(
            $this->metadata(suggests: [['id' => 'Psr\\Log\\LoggerInterface', 'interface' => 'Psr\\Log\\LoggerInterface']]),
            'Milpa\\Plugins\\FixturePlugin',
            'FixturePlugin.php',
            $warnings,
        );

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('Psr\\Log\\LoggerInterface', $warnings[0]);
        $this->assertStringContainsString('suggests', $warnings[0]);
    }

    public function testADeclaredConstraintEvenTheExplicitWildcardStaysSilent(): void
    {
        $warnings = [];
        PluginManifest::generateFromMetadata(
            $this->metadata(requires: [self::REQUIRES_NO_CONSTRAINT + ['constraint' => '*']]),
            'Milpa\\Plugins\\FixturePlugin',
            'FixturePlugin.php',
            $warnings,
        );

        $this->assertSame([], $warnings, 'a declared constraint — even `*` — is the author speaking; no nudge');
    }

    /**
     * Rich-shaped metadata with one real provides record (service resolves) plus the lists under test.
     *
     * @param list<array<string, mixed>> $requires
     * @param list<array<string, mixed>> $suggests
     *
     * @return array<string, mixed>
     */
    private function metadata(array $requires = [], array $suggests = []): array
    {
        return [
            'name' => 'FixturePlugin',
            'version' => '1.0.0',
            'author' => 'Acme',
            'site' => 'https://example.com',
            'type' => 'Service',
            'provides' => [
                [
                    'id' => 'crm.fixture.mailer.v1',
                    'interface' => 'Milpa\\Plugin\\Tests\\Fixtures\\FixtureMailerInterface',
                    'contractVersion' => '1.0.0',
                    'service' => 'Milpa\\Plugin\\Tests\\Fixtures\\FixtureMailer',
                ],
            ],
            'requires' => $requires,
            'suggests' => $suggests,
        ];
    }
}
