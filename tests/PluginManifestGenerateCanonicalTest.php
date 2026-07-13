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
use Milpa\Plugin\Tests\Fixtures\FixtureMailer;
use Milpa\Plugin\Tests\Fixtures\FixtureMailerInterface;
use Milpa\Plugin\Tests\Fixtures\FixtureNotAMailer;
use PHPUnit\Framework\TestCase;

/**
 * The canonical generator (Cosecha T2): `generateFromMetadata()` emits the
 * canonical `capabilities` block when — and only when — the `#[PluginMetadata]`
 * entries are rich records, validated at generation time (provider `service`
 * present, autoloadable, implementing the declared interface). Bare-FQCN
 * strings keep emitting the legacy `contracts` block byte-identically, plus a
 * visible warning teaching how to reach canonical. Mixing both shapes in one
 * plugin hard-fails: a plugin migrates atomically.
 *
 * The rich→canonical assertions are driven by the REAL schema file
 * (`schema/milpa-plugin.schema.json`): required keys, allowed keys
 * (additionalProperties: false), property types, and the fqcn/semver regex
 * patterns are all read from the schema, so the schema stays the contract.
 */
final class PluginManifestGenerateCanonicalTest extends TestCase
{
    /**
     * A rich `provides` record wired to the real fixture contract/service pair.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function richProvides(array $overrides = []): array
    {
        return array_merge([
            'id' => 'fixture.mailer.v1',
            'interface' => FixtureMailerInterface::class,
            'contractVersion' => '1.2.0',
            'service' => FixtureMailer::class,
        ], $overrides);
    }

    /**
     * Metadata for the fixture plugin with the given capability lists.
     *
     * @param array<int, mixed> $provides
     * @param array<int, mixed> $requires
     * @param array<int, mixed> $suggests
     *
     * @return array<string, mixed>
     */
    private function metadataWith(array $provides = [], array $requires = [], array $suggests = []): array
    {
        return [
            'name' => 'MailPlugin',
            'version' => '1.0.0',
            'author' => 'Acme',
            'site' => 'https://example.test',
            'type' => 'Service',
            'provides' => $provides,
            'requires' => $requires,
            'suggests' => $suggests,
        ];
    }

    /**
     * Run the generator with the standard namespace/entrypoint pair.
     *
     * @param array<string, mixed> $metadata
     * @param list<string>         $warnings
     *
     * @return array<string, mixed>
     */
    private function generate(array $metadata, array &$warnings = []): array
    {
        return PluginManifest::generateFromMetadata(
            metadata: $metadata,
            namespace: 'Acme\\MailPlugin',
            entrypoint: 'MailPlugin.php',
            warnings: $warnings,
        );
    }

    // =========================================================================
    // Rich records → canonical `capabilities` block, schema-conformant
    // =========================================================================

    public function testRichRecordsEmitCanonicalCapabilitiesConformingToTheRealSchema(): void
    {
        $warnings = ['stale entry that must be reset'];
        $data = $this->generate($this->metadataWith(
            provides: [$this->richProvides(['priority' => 10, 'exclusive' => false])],
            requires: [[
                'id' => 'fixture.storage.v1',
                'interface' => 'Milpa\\Plugin\\Tests\\Fixtures\\FixtureStorageInterface',
                'constraint' => '^1.0',
                'oneOf' => ['fixture.storage.sql.v1', 'fixture.storage.memory.v1'],
            ]],
            suggests: [[
                'id' => 'fixture.telemetry.v1',
                'interface' => 'Milpa\\Plugin\\Tests\\Fixtures\\FixtureTelemetryInterface',
                'constraint' => '^2.0',
                'fallback' => 'noop',
            ]],
        ), $warnings);

        $this->assertArrayNotHasKey('contracts', $data, 'canonical output must not carry the legacy block');
        $this->assertSame([], $warnings, 'canonical generation warns about nothing (and resets caller-provided noise)');

        $this->assertSame([
            'id' => 'fixture.mailer.v1',
            'interface' => FixtureMailerInterface::class,
            'contractVersion' => '1.2.0',
            'service' => FixtureMailer::class,
            'priority' => 10,
            'exclusive' => false,
        ], $data['capabilities']['provides'][0]);

        $this->assertSame([
            'id' => 'fixture.storage.v1',
            'interface' => 'Milpa\\Plugin\\Tests\\Fixtures\\FixtureStorageInterface',
            'constraint' => '^1.0',
            'oneOf' => ['fixture.storage.sql.v1', 'fixture.storage.memory.v1'],
        ], $data['capabilities']['requires'][0]);

        $this->assertSame([
            'id' => 'fixture.telemetry.v1',
            'interface' => 'Milpa\\Plugin\\Tests\\Fixtures\\FixtureTelemetryInterface',
            'constraint' => '^2.0',
            'fallback' => 'noop',
        ], $data['capabilities']['suggests'][0]);

        $this->assertManifestConformsToCanonicalSchema($data);
    }

    public function testRichRecordsEmitOnlyDeclaredOptionalFieldsAndNormalizeRequiredOnes(): void
    {
        $data = $this->generate($this->metadataWith(
            provides: [$this->richProvides()],
            requires: [[
                'id' => 'fixture.storage.v1',
                'interface' => 'Milpa\\Plugin\\Tests\\Fixtures\\FixtureStorageInterface',
                // no constraint: the record VO's documented default is `*` (any version),
                // and the schema REQUIRES the key — normalization, not invention.
            ]],
            suggests: [[
                'id' => 'fixture.telemetry.v1',
                'interface' => 'Milpa\\Plugin\\Tests\\Fixtures\\FixtureTelemetryInterface',
                // no fallback: omitted stays omitted.
            ]],
        ));

        $this->assertSame(
            ['id', 'interface', 'contractVersion', 'service'],
            array_keys($data['capabilities']['provides'][0]),
            'undeclared priority/exclusive must not be invented into the record'
        );
        $this->assertSame('*', $data['capabilities']['requires'][0]['constraint']);
        $this->assertArrayNotHasKey('oneOf', $data['capabilities']['requires'][0]);
        $this->assertSame('*', $data['capabilities']['suggests'][0]['constraint']);
        $this->assertArrayNotHasKey('fallback', $data['capabilities']['suggests'][0]);

        $this->assertManifestConformsToCanonicalSchema($data);
    }

    // =========================================================================
    // Bare strings → legacy `contracts` block byte-identical + warning
    // =========================================================================

    public function testBareStringsEmitLegacyContractsByteIdenticalPlusWarning(): void
    {
        $warnings = [];
        $data = $this->generate($this->metadataWith(
            provides: ['Acme\\MailPlugin\\MailerInterface'],
            requires: ['Acme\\Contracts\\StorageInterface'],
            suggests: ['Acme\\Contracts\\TelemetryInterface'],
        ), $warnings);

        $this->assertArrayNotHasKey('capabilities', $data, 'legacy output must not grow a canonical block');
        $this->assertSame([
            'provides' => ['Acme\\MailPlugin\\MailerInterface'],
            'requires' => ['Acme\\Contracts\\StorageInterface'],
            'suggests' => ['Acme\\Contracts\\TelemetryInterface'],
        ], $data['contracts']);

        // The full shape stays exactly what the generator emitted before this change.
        $this->assertEquals([
            'name' => 'milpa/mailplugin',
            'displayName' => 'MailPlugin',
            'description' => '',
            'version' => '1.0.0',
            'type' => 'Service',
            'license' => 'MIT',
            'authors' => [['name' => 'Acme', 'email' => '']],
            'milpa' => ['min-version' => '2.0.0', 'php-version' => '>=8.2'],
            'contracts' => [
                'provides' => ['Acme\\MailPlugin\\MailerInterface'],
                'requires' => ['Acme\\Contracts\\StorageInterface'],
                'suggests' => ['Acme\\Contracts\\TelemetryInterface'],
            ],
            'dependencies' => ['plugins' => (object) [], 'composer' => (object) []],
            'entrypoint' => 'MailPlugin.php',
            'namespace' => 'Acme\\MailPlugin',
            'migrations' => ['directory' => 'Migrations'],
            'env-vars' => [],
        ], $data);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('MailPlugin', $warnings[0]);
        $this->assertStringContainsString('legacy', $warnings[0]);
        $this->assertStringContainsString('Enrich #[PluginMetadata]', $warnings[0]);
        $this->assertStringContainsString('canonical', $warnings[0]);
    }

    public function testNoCapabilityEntriesStaysLegacyShapedWithoutWarning(): void
    {
        $warnings = [];
        $data = $this->generate($this->metadataWith(), $warnings);

        $this->assertSame(
            ['provides' => [], 'requires' => [], 'suggests' => []],
            $data['contracts'],
            'a plugin declaring nothing keeps the empty legacy block byte-identically'
        );
        $this->assertArrayNotHasKey('capabilities', $data);
        $this->assertSame([], $warnings, 'nothing to migrate, nothing to warn about');
    }

    // =========================================================================
    // BINDING (T1 adjudication b): provides record without `service` hard-fails
    // =========================================================================

    public function testRichProvidesWithoutServiceHardFailsWithTeachingMessage(): void
    {
        $record = $this->richProvides();
        unset($record['service']);

        try {
            $this->generate($this->metadataWith(provides: [$record]));
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('fixture.mailer.v1', $e->getMessage());
            $this->assertStringContainsString('`service`', $e->getMessage());
            $this->assertStringContainsString('milpa-plugin.schema.json', $e->getMessage());
            $this->assertStringContainsString('never invents metadata', $e->getMessage());
        }
    }

    // =========================================================================
    // Provider check at generation (mirrors ProviderImplementsValidator)
    // =========================================================================

    public function testServiceThatDoesNotAutoloadHardFails(): void
    {
        try {
            $this->generate($this->metadataWith(provides: [
                $this->richProvides(['service' => 'Milpa\\Plugin\\Tests\\Fixtures\\DoesNotExist']),
            ]));
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Milpa\\Plugin\\Tests\\Fixtures\\DoesNotExist', $e->getMessage());
            $this->assertStringContainsString('does not autoload', $e->getMessage());
        }
    }

    public function testServiceNotImplementingTheInterfaceHardFails(): void
    {
        try {
            $this->generate($this->metadataWith(provides: [
                $this->richProvides(['service' => FixtureNotAMailer::class]),
            ]));
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString(FixtureNotAMailer::class, $e->getMessage());
            $this->assertStringContainsString('does not implement', $e->getMessage());
            $this->assertStringContainsString(FixtureMailerInterface::class, $e->getMessage());
        }
    }

    public function testProvidesInterfaceThatDoesNotAutoloadHardFails(): void
    {
        try {
            $this->generate($this->metadataWith(provides: [
                $this->richProvides(['interface' => 'Milpa\\Plugin\\Tests\\Fixtures\\GhostInterface']),
            ]));
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Milpa\\Plugin\\Tests\\Fixtures\\GhostInterface', $e->getMessage());
            $this->assertStringContainsString('no such type autoloads', $e->getMessage());
        }
    }

    // =========================================================================
    // Mixed shapes → hard-fail: a plugin migrates atomically
    // =========================================================================

    public function testMixedBareAndRichEntriesAcrossListsHardFailWithAtomicMigrationTeaching(): void
    {
        try {
            $this->generate($this->metadataWith(
                provides: [$this->richProvides()],
                requires: ['Acme\\Contracts\\StorageInterface'],
            ));
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('MailPlugin', $e->getMessage());
            $this->assertStringContainsString('mixes', $e->getMessage());
            $this->assertStringContainsString('Migrate all entries of this plugin together', $e->getMessage());
        }
    }

    public function testMixedEntriesWithinOneListHardFailTheSameWay(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Migrate all entries of this plugin together');

        $this->generate($this->metadataWith(provides: [
            $this->richProvides(),
            'Acme\\MailPlugin\\MailerInterface',
        ]));
    }

    // =========================================================================
    // Malformed entries and unknown keys never emit silently-broken manifests
    // =========================================================================

    public function testEntryThatIsNeitherStringNorRecordHardFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('provides entry #0');

        $this->generate($this->metadataWith(provides: [42]));
    }

    public function testUnknownRecordKeysHardFailInsteadOfBeingDroppedSilently(): void
    {
        try {
            $this->generate($this->metadataWith(provides: [
                $this->richProvides(['prioritty' => 5]),
            ]));
            self::fail('expected InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('prioritty', $e->getMessage());
            $this->assertStringContainsString('unknown key', $e->getMessage());
            $this->assertStringContainsString('refuses to drop declared metadata', $e->getMessage());
        }
    }

    public function testRecordValidationDelegatesToTheCoreValueObjects(): void
    {
        // No contractVersion → the core provision VO's semver validation is the failure.
        $record = $this->richProvides();
        unset($record['contractVersion']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid semantic version');

        $this->generate($this->metadataWith(provides: [$record]));
    }

    // =========================================================================
    // Schema-driven assertions (schema/milpa-plugin.schema.json is the contract)
    // =========================================================================

    /**
     * Load the canonical schema. Two real locations: the package root
     * (`schema/` — export-plugin.sh ships a copy there, so these
     * schema-conformance tests run in the standalone export too) and the
     * monorepo root (the single source of truth the copy is taken from).
     * The package-root probe wins when both exist — the export tests what
     * it ships. Neither present means a hand-rolled checkout: skip, loudly.
     *
     * @return array<string, mixed>
     */
    private function loadCanonicalSchema(): array
    {
        $candidates = [
            \dirname(__DIR__) . '/schema/milpa-plugin.schema.json',
            \dirname(__DIR__, 3) . '/schema/milpa-plugin.schema.json',
        ];
        foreach ($candidates as $path) {
            if (is_file($path)) {
                return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        self::markTestSkipped('canonical schema (schema/milpa-plugin.schema.json) found neither at the package root (shipped by export-plugin.sh) nor at the monorepo root');
    }

    /**
     * Assert the generated manifest conforms to the REAL canonical schema:
     * top-level required keys and name/version patterns, plus every emitted
     * capability record checked against its `$defs` record definition —
     * required keys, allowed keys (additionalProperties: false), property
     * types, and the fqcn/semver patterns, all read from the schema file.
     *
     * @param array<string, mixed> $manifest
     */
    private function assertManifestConformsToCanonicalSchema(array $manifest): void
    {
        $schema = $this->loadCanonicalSchema();

        foreach ($schema['required'] as $key) {
            $this->assertArrayHasKey($key, $manifest, "schema requires top-level `{$key}`");
        }
        $this->assertMatchesRegularExpression('~' . $schema['properties']['name']['pattern'] . '~', $manifest['name']);
        $this->assertMatchesRegularExpression('~' . $schema['properties']['version']['pattern'] . '~', $manifest['version']);

        $capabilitiesSchema = $schema['properties']['capabilities'];
        $this->assertFalse($capabilitiesSchema['additionalProperties'], 'schema sanity: capabilities is a closed object');

        $this->assertIsArray($manifest['capabilities']);
        foreach ($manifest['capabilities'] as $kind => $records) {
            $this->assertArrayHasKey($kind, $capabilitiesSchema['properties'], "additionalProperties=false: `{$kind}` not allowed under capabilities");

            $ref = $capabilitiesSchema['properties'][$kind]['items']['$ref'];
            $defName = substr($ref, strlen('#/$defs/'));

            $this->assertIsArray($records);
            foreach ($records as $record) {
                $this->assertRecordConformsToDef($record, $defName, $schema);
            }
        }
    }

    /**
     * Assert one emitted capability record against a `$defs` record definition
     * of the real schema.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $schema
     */
    private function assertRecordConformsToDef(array $record, string $defName, array $schema): void
    {
        $def = $schema['$defs'][$defName];

        foreach ($def['required'] as $requiredKey) {
            $this->assertArrayHasKey($requiredKey, $record, "{$defName} requires `{$requiredKey}`");
        }

        $this->assertFalse($def['additionalProperties'], "schema sanity: {$defName} is a closed object");

        foreach ($record as $key => $value) {
            $this->assertArrayHasKey($key, $def['properties'], "additionalProperties=false: `{$key}` not allowed on {$defName}");
            $this->assertValueMatchesPropertySchema($value, $def['properties'][$key], "{$defName}.{$key}", $schema);
        }
    }

    /**
     * Assert one record value against its property schema (type keyword,
     * `$ref` pattern definitions, and string-array items).
     *
     * @param array<string, mixed> $property
     * @param array<string, mixed> $schema
     */
    private function assertValueMatchesPropertySchema(mixed $value, array $property, string $where, array $schema): void
    {
        if (isset($property['$ref'])) {
            $refDef = $schema['$defs'][substr($property['$ref'], strlen('#/$defs/'))];
            $this->assertIsString($value, $where);
            $this->assertMatchesRegularExpression('~' . $refDef['pattern'] . '~', $value, $where);

            return;
        }

        match ($property['type']) {
            'string' => $this->assertIsString($value, $where),
            'integer' => $this->assertIsInt($value, $where),
            'boolean' => $this->assertIsBool($value, $where),
            'array' => $this->assertIsArray($value, $where),
            default => self::fail("{$where}: unhandled schema type `{$property['type']}` — extend the test validator"),
        };

        if ($property['type'] === 'array' && isset($property['items']['type']) && $property['items']['type'] === 'string') {
            foreach ((array) $value as $item) {
                $this->assertIsString($item, "{$where}[]");
            }
        }
    }
}
