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

use Milpa\Plugin\ContractResolver;
use Milpa\Plugin\DependencyResolver;
use Milpa\Plugin\PluginManifest;
use PHPUnit\Framework\TestCase;

/**
 * The read-path dual of the canonical generator (T2): every reader that consumed the legacy
 * `contracts.*` lists must also speak the canonical `capabilities.*` records, or the first
 * regenerated manifest goes invisible to `coa:plugins deps/info/list` (the T2-Major finding).
 *
 * Pins, per reader:
 * - {@see PluginManifest::toMetadataArray()} carries the RAW capability records of a canonical
 *   manifest (and stays byte-identical for a legacy one) — this array IS `Plugins::$plugins`,
 *   the boot path's input;
 * - {@see DependencyResolver::resolve()} closes a canonical `requires` record against installed
 *   provides in BOTH shapes, matching by capability identity (id, interface, or a `oneOf`
 *   alternative — the same superset posture core's CapabilityGraphChecker adjudicated in T1);
 * - {@see ContractResolver} (deps/simulate's engine) maps a rich provision under BOTH its id and
 *   its interface, satisfies rich requirements by either, and still orders the load
 *   topologically; bare strings keep their exact legacy behavior.
 */
final class CanonicalReadersTest extends TestCase
{
    private const CANONICAL = [
        'name' => 'milpa/fixtureplugin',
        'displayName' => 'FixturePlugin',
        'version' => '1.0.0',
        'type' => 'Service',
        'capabilities' => [
            'provides' => [
                [
                    'id' => 'crm.fixture.mailer.v1',
                    'interface' => 'Milpa\\Plugin\\Tests\\Fixtures\\FixtureMailerInterface',
                    'contractVersion' => '1.0.0',
                    'service' => 'Milpa\\Plugin\\Tests\\Fixtures\\FixtureMailer',
                ],
            ],
            'requires' => [
                [
                    'id' => 'crm.fixture.storage.v1',
                    'interface' => 'Milpa\\Fixtures\\StorageInterface',
                    'constraint' => '^1.0',
                ],
            ],
            'suggests' => [
                [
                    'id' => 'Psr\\Log\\LoggerInterface',
                    'interface' => 'Psr\\Log\\LoggerInterface',
                    'constraint' => '*',
                ],
            ],
        ],
        'entrypoint' => 'FixturePlugin.php',
        'namespace' => 'Milpa\\Plugins\\FixturePlugin',
    ];

    private const LEGACY = [
        'name' => 'milpa/legacyplugin',
        'displayName' => 'LegacyPlugin',
        'version' => '1.0.0',
        'type' => 'Service',
        'contracts' => [
            'provides' => ['Milpa\\Fixtures\\AlphaInterface'],
            'requires' => ['Milpa\\Fixtures\\BetaInterface'],
            'suggests' => ['Milpa\\Fixtures\\GammaInterface'],
        ],
        'entrypoint' => 'LegacyPlugin.php',
        'namespace' => 'Milpa\\Plugins\\LegacyPlugin',
    ];

    // ---------------------------------------------------------------- toMetadataArray()

    public function testToMetadataArrayCarriesTheCanonicalRecordsVerbatim(): void
    {
        $metadata = PluginManifest::fromArray(self::CANONICAL)->toMetadataArray();

        $this->assertSame(self::CANONICAL['capabilities']['provides'], $metadata['provides']);
        $this->assertSame(self::CANONICAL['capabilities']['requires'], $metadata['requires']);
        $this->assertSame(self::CANONICAL['capabilities']['suggests'], $metadata['suggests']);
    }

    public function testToMetadataArrayStaysByteIdenticalForALegacyManifest(): void
    {
        $metadata = PluginManifest::fromArray(self::LEGACY)->toMetadataArray();

        $this->assertSame(['Milpa\\Fixtures\\AlphaInterface'], $metadata['provides']);
        $this->assertSame(['Milpa\\Fixtures\\BetaInterface'], $metadata['requires']);
        $this->assertSame(['Milpa\\Fixtures\\GammaInterface'], $metadata['suggests']);
    }

    // ---------------------------------------------------------------- DependencyResolver

    public function testCanonicalRequiresIsSatisfiedByAnInstalledRichProvisionById(): void
    {
        $resolution = $this->resolveAgainst([
            ['name' => 'StoragePlugin', 'version' => '1.0.0', 'provides' => [
                ['id' => 'crm.fixture.storage.v1', 'interface' => 'Milpa\\Fixtures\\StorageInterface', 'contractVersion' => '1.0.0', 'service' => 'Milpa\\Fixtures\\Storage'],
            ]],
        ]);

        $this->assertTrue($resolution->resolvable);
        $this->assertSame(['crm.fixture.storage.v1'], $resolution->satisfiedContracts);
        $this->assertSame([], $resolution->conflicts);
    }

    public function testCanonicalRequiresIsSatisfiedByALegacyBareProvisionThroughItsInterface(): void
    {
        // The provider has not migrated yet: it still provides the bare FQCN. The requirement's
        // `interface` is the bridge — the same one-directional superset the T1 checker pinned.
        $resolution = $this->resolveAgainst([
            ['name' => 'StoragePlugin', 'version' => '1.0.0', 'provides' => ['Milpa\\Fixtures\\StorageInterface']],
        ]);

        $this->assertTrue($resolution->resolvable);
        $this->assertSame(['crm.fixture.storage.v1'], $resolution->satisfiedContracts);
    }

    public function testCanonicalRequiresWithNoProviderConflictsNamingTheCapabilityId(): void
    {
        $resolution = $this->resolveAgainst([
            ['name' => 'UnrelatedPlugin', 'version' => '1.0.0', 'provides' => ['Milpa\\Fixtures\\OtherInterface']],
        ]);

        $this->assertFalse($resolution->resolvable);
        $this->assertSame(['Missing contract: crm.fixture.storage.v1'], $resolution->conflicts);
    }

    public function testLegacyBareRequiresBehaviorIsUnchanged(): void
    {
        $rootPath = sys_get_temp_dir();
        $manifest = PluginManifest::fromArray(self::LEGACY);

        $satisfied = (new DependencyResolver($rootPath))->resolve($manifest, [
            ['name' => 'BetaPlugin', 'version' => '1.0.0', 'provides' => ['Milpa\\Fixtures\\BetaInterface']],
        ]);
        $this->assertTrue($satisfied->resolvable);
        $this->assertSame(['Milpa\\Fixtures\\BetaInterface'], $satisfied->satisfiedContracts);

        $missing = (new DependencyResolver($rootPath))->resolve($manifest, []);
        $this->assertFalse($missing->resolvable);
        $this->assertSame(['Missing contract: Milpa\\Fixtures\\BetaInterface'], $missing->conflicts);
    }

    // ---------------------------------------------------------------- ContractResolver

    public function testContractResolverSatisfiesARichRequirementByIdAndByInterface(): void
    {
        $provider = ['name' => 'ProviderPlugin', 'provides' => [
            ['id' => 'crm.fixture.mailer.v1', 'interface' => 'Milpa\\Fixtures\\MailerInterface', 'contractVersion' => '1.0.0', 'service' => 'Milpa\\Fixtures\\Mailer'],
        ]];
        $byId = ['name' => 'ByIdPlugin', 'requires' => [
            ['id' => 'crm.fixture.mailer.v1', 'interface' => 'Milpa\\Fixtures\\MailerInterface', 'constraint' => '^1.0'],
        ]];
        $byInterface = ['name' => 'ByInterfacePlugin', 'requires' => ['Milpa\\Fixtures\\MailerInterface']];

        $resolver = new ContractResolver();
        $resolver->validate([$provider, $byId, $byInterface]);

        $order = array_column($resolver->getLoadOrder([$byId, $byInterface, $provider]), 'name');
        $this->assertSame('ProviderPlugin', $order[0], 'the provider must boot before both requirers');

        $contracts = $resolver->getAvailableContracts([$provider]);
        $this->assertSame('ProviderPlugin', $contracts['crm.fixture.mailer.v1']);
        $this->assertSame('ProviderPlugin', $contracts['Milpa\\Fixtures\\MailerInterface']);
    }

    public function testContractResolverStillThrowsTheLegacyLessonForAnUnmetRichRequirement(): void
    {
        $orphan = ['name' => 'OrphanPlugin', 'requires' => [
            ['id' => 'crm.fixture.ghost.v1', 'interface' => 'Milpa\\Fixtures\\GhostInterface', 'constraint' => '^1.0'],
        ]];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/Plugin 'OrphanPlugin' requires 'crm\\.fixture\\.ghost\\.v1' but no plugin provides it/");

        (new ContractResolver())->validate([$orphan]);
    }

    /**
     * Resolve the canonical fixture manifest against a given installed set.
     *
     * @param array<int, array<string, mixed>> $installedPlugins
     */
    private function resolveAgainst(array $installedPlugins): \Milpa\DTO\DependencyResolution
    {
        $manifest = PluginManifest::fromArray(self::CANONICAL);

        return (new DependencyResolver(sys_get_temp_dir()))->resolve($manifest, $installedPlugins);
    }
}
