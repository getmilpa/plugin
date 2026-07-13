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
use Milpa\ValueObjects\SemanticVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PluginManifestTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpFile = sys_get_temp_dir() . '/milpa_manifest_' . uniqid() . '.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            unlink($this->tmpFile);
        }
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function baseData(array $extra = []): array
    {
        return array_merge([
            'name' => 'acme/mail-plugin',
            'version' => '1.2.3',
            'entrypoint' => 'MailPlugin.php',
            'namespace' => 'Acme\\MailPlugin',
        ], $extra);
    }

    // =========================================================================
    // fromPath() / fromArray()
    // =========================================================================

    public function testFromPathReadsAndParsesManifest(): void
    {
        file_put_contents($this->tmpFile, json_encode($this->baseData(), JSON_THROW_ON_ERROR));

        $manifest = PluginManifest::fromPath($this->tmpFile);

        $this->assertSame('acme/mail-plugin', $manifest->getName());
        $this->assertTrue($manifest->getVersion()->equals(SemanticVersion::parse('1.2.3')));
    }

    public function testFromPathThrowsWhenFileMissing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Manifest not found');

        PluginManifest::fromPath($this->tmpFile);
    }

    public function testFromPathThrowsOnInvalidJson(): void
    {
        file_put_contents($this->tmpFile, '{not valid json');

        $this->expectException(\JsonException::class);

        PluginManifest::fromPath($this->tmpFile);
    }

    public function testFromArrayDefaultsVersionWhenAbsent(): void
    {
        $manifest = PluginManifest::fromArray(['name' => 'acme/x']);

        $this->assertTrue($manifest->getVersion()->equals(SemanticVersion::parse('0.0.0')));
    }

    public function testFromArrayThrowsOnInvalidVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PluginManifest::fromArray($this->baseData(['version' => 'not-a-version']));
    }

    // =========================================================================
    // validate()
    // =========================================================================

    public function testValidatePassesForCompleteManifest(): void
    {
        $manifest = PluginManifest::fromArray($this->baseData());

        $manifest->validate();
        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function requiredFieldsProvider(): array
    {
        return [
            'name' => ['name'],
            'version' => ['version'],
            'entrypoint' => ['entrypoint'],
            'namespace' => ['namespace'],
        ];
    }

    #[DataProvider('requiredFieldsProvider')]
    public function testValidateThrowsWhenRequiredFieldMissing(string $field): void
    {
        $data = $this->baseData();
        unset($data[$field]);
        $manifest = PluginManifest::fromArray($data);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("missing required field: {$field}");

        $manifest->validate();
    }

    public function testValidateThrowsOnInvalidType(): void
    {
        $manifest = PluginManifest::fromArray($this->baseData(['type' => 'NotAType']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid plugin type 'NotAType'");

        $manifest->validate();
    }

    public function testValidateAcceptsEachKnownType(): void
    {
        foreach (['Web', 'CLI', 'Mixed', 'Service'] as $type) {
            $manifest = PluginManifest::fromArray($this->baseData(['type' => $type]));
            $manifest->validate();
        }
        $this->addToAssertionCount(1);
    }

    public function testValidateThrowsWhenPhpVersionConstraintUnsatisfied(): void
    {
        $manifest = PluginManifest::fromArray($this->baseData([
            'milpa' => ['php-version' => '>=99.0'],
        ]));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires PHP >=99.0');

        $manifest->validate();
    }

    public function testValidatePassesWhenPhpVersionConstraintSatisfied(): void
    {
        $manifest = PluginManifest::fromArray($this->baseData([
            'milpa' => ['php-version' => '>=8.0'],
        ]));

        $manifest->validate();
        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // Simple getters
    // =========================================================================

    public function testGetDisplayNameUsesExplicitValue(): void
    {
        $manifest = PluginManifest::fromArray($this->baseData(['displayName' => 'Mail Plugin']));

        $this->assertSame('Mail Plugin', $manifest->getDisplayName());
    }

    public function testGetDisplayNameDerivesFromPackageName(): void
    {
        $manifest = PluginManifest::fromArray($this->baseData(['name' => 'acme/mail-plugin']));

        $this->assertSame('MailPlugin', $manifest->getDisplayName());
    }

    public function testDefaultTypeIsMixed(): void
    {
        $manifest = PluginManifest::fromArray($this->baseData());

        $this->assertSame('Mixed', $manifest->getType());
    }

    public function testGetProvidesRequiresSuggestsReadFromContracts(): void
    {
        $manifest = PluginManifest::fromArray($this->baseData([
            'contracts' => [
                'provides' => ['FooInterface'],
                'requires' => ['BarInterface'],
                'suggests' => ['BazInterface'],
            ],
        ]));

        $this->assertSame(['FooInterface'], $manifest->getProvides());
        $this->assertSame(['BarInterface'], $manifest->getRequires());
        $this->assertSame(['BazInterface'], $manifest->getSuggests());
    }

    public function testGetComposerAndPluginDependencies(): void
    {
        $manifest = PluginManifest::fromArray($this->baseData([
            'dependencies' => [
                'composer' => ['symfony/mailer' => '^7.0'],
                'plugins' => ['OtherPlugin' => '^1.0'],
            ],
        ]));

        $this->assertSame(['symfony/mailer' => '^7.0'], $manifest->getComposerDependencies());
        $this->assertSame(['OtherPlugin' => '^1.0'], $manifest->getPluginDependencies());
    }

    public function testGetMinMilpaVersionAndPhpVersion(): void
    {
        $manifest = PluginManifest::fromArray($this->baseData([
            'milpa' => ['min-version' => '2.0.0', 'php-version' => '>=8.3'],
        ]));

        $this->assertSame('2.0.0', $manifest->getMinMilpaVersion());
        $this->assertSame('>=8.3', $manifest->getPhpVersion());
    }

    public function testGetMinMilpaVersionDefaultsNull(): void
    {
        $manifest = PluginManifest::fromArray($this->baseData());

        $this->assertNull($manifest->getMinMilpaVersion());
        $this->assertNull($manifest->getPhpVersion());
    }

    public function testGetEnvVars(): void
    {
        $manifest = PluginManifest::fromArray($this->baseData(['env-vars' => ['MAIL_DSN']]));

        $this->assertSame(['MAIL_DSN'], $manifest->getEnvVars());
    }

    public function testGetMigrationsDirectory(): void
    {
        $manifest = PluginManifest::fromArray($this->baseData(['migrations' => ['directory' => 'Migrations']]));

        $this->assertSame('Migrations', $manifest->getMigrationsDirectory());
    }

    public function testGetAuthors(): void
    {
        $authors = [['name' => 'Acme', 'email' => 'dev@acme.test']];
        $manifest = PluginManifest::fromArray($this->baseData(['authors' => $authors]));

        $this->assertSame($authors, $manifest->getAuthors());
    }

    public function testGetRawDataReturnsUnderlyingArray(): void
    {
        $data = $this->baseData();
        $manifest = PluginManifest::fromArray($data);

        $this->assertSame($data, $manifest->getRawData());
    }

    // =========================================================================
    // toMetadataArray()
    // =========================================================================

    public function testToMetadataArrayShape(): void
    {
        $manifest = PluginManifest::fromArray($this->baseData([
            'displayName' => 'Mail Plugin',
            'authors' => [['name' => 'Acme']],
            'type' => 'Service',
            'contracts' => ['provides' => ['X'], 'requires' => ['Y'], 'suggests' => ['Z']],
        ]));

        $this->assertSame([
            'name' => 'Mail Plugin',
            'version' => '1.2.3',
            'author' => 'Acme',
            'site' => '',
            'type' => 'Service',
            'provides' => ['X'],
            'requires' => ['Y'],
            'suggests' => ['Z'],
        ], $manifest->toMetadataArray());
    }

    // =========================================================================
    // generateFromMetadata()
    // =========================================================================

    public function testGenerateFromMetadataProducesInstallableShape(): void
    {
        $data = PluginManifest::generateFromMetadata(
            metadata: [
                'name' => 'MailPlugin',
                'version' => '1.0.0',
                'author' => 'Acme',
                'type' => 'Service',
                'provides' => ['MailerInterface'],
                'requires' => [],
                'suggests' => [],
            ],
            namespace: 'Acme\\MailPlugin',
            entrypoint: 'MailPlugin.php',
        );

        $this->assertSame('milpa/mailplugin', $data['name']);
        $this->assertSame('MailPlugin', $data['displayName']);
        $this->assertSame('Service', $data['type']);
        $this->assertSame(['MailerInterface'], $data['contracts']['provides']);
        $this->assertSame('Acme\\MailPlugin', $data['namespace']);
        $this->assertSame('MailPlugin.php', $data['entrypoint']);

        // The generated shape must itself be a valid, loadable manifest.
        $manifest = PluginManifest::fromArray($data);
        $manifest->validate();
        $this->addToAssertionCount(1);
    }

    // =========================================================================
    // toJson()
    // =========================================================================

    public function testToJsonRoundTrips(): void
    {
        $data = $this->baseData(['description' => 'A mail plugin']);
        $manifest = PluginManifest::fromArray($data);

        $decoded = json_decode($manifest->toJson(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame($data, $decoded);
    }
}
