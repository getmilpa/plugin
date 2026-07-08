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

use Milpa\Plugin\DependencyResolver;
use Milpa\Plugin\PluginManifest;
use PHPUnit\Framework\TestCase;

final class DependencyResolverTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rootPath = sys_get_temp_dir() . '/milpa_dep_resolver_' . uniqid();
        mkdir($this->rootPath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->rootPath);
        parent::tearDown();
    }

    private function manifest(array $extra = []): PluginManifest
    {
        return PluginManifest::fromArray(array_merge([
            'name' => 'acme/mail-plugin',
            'version' => '1.0.0',
            'entrypoint' => 'MailPlugin.php',
            'namespace' => 'Acme\\MailPlugin',
        ], $extra));
    }

    private function writeComposerLock(array $packages = [], array $packagesDev = []): void
    {
        file_put_contents(
            $this->rootPath . '/composer.lock',
            json_encode(['packages' => $packages, 'packages-dev' => $packagesDev], JSON_THROW_ON_ERROR)
        );
    }

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

    // =========================================================================
    // resolve() — contract requirements
    // =========================================================================

    public function testResolveWithNoRequirementsIsResolvable(): void
    {
        $resolver = new DependencyResolver($this->rootPath);

        $resolution = $resolver->resolve($this->manifest(), []);

        $this->assertTrue($resolution->resolvable);
        $this->assertSame([], $resolution->conflicts);
    }

    public function testResolveSatisfiedContractIsRecorded(): void
    {
        $resolver = new DependencyResolver($this->rootPath);
        $manifest = $this->manifest(['contracts' => ['requires' => ['database']]]);
        $installed = [['name' => 'db-plugin', 'version' => '1.0.0', 'provides' => ['database']]];

        $resolution = $resolver->resolve($manifest, $installed);

        $this->assertTrue($resolution->resolvable);
        $this->assertSame(['database'], $resolution->satisfiedContracts);
    }

    public function testResolveMissingContractIsConflict(): void
    {
        $resolver = new DependencyResolver($this->rootPath);
        $manifest = $this->manifest(['contracts' => ['requires' => ['database']]]);

        $resolution = $resolver->resolve($manifest, []);

        $this->assertFalse($resolution->resolvable);
        $this->assertSame(['Missing contract: database'], $resolution->conflicts);
    }

    // =========================================================================
    // resolve() — plugin dependencies (semver constraints)
    // =========================================================================

    public function testResolveMissingPluginDependency(): void
    {
        $resolver = new DependencyResolver($this->rootPath);
        $manifest = $this->manifest(['dependencies' => ['plugins' => ['vendor/other-plugin' => '^1.0']]]);

        $resolution = $resolver->resolve($manifest, []);

        $this->assertFalse($resolution->resolvable);
        $this->assertSame(['vendor/other-plugin'], $resolution->missingPlugins);
    }

    public function testResolvePluginDependencySatisfyingCaretConstraint(): void
    {
        $resolver = new DependencyResolver($this->rootPath);
        $manifest = $this->manifest(['dependencies' => ['plugins' => ['OtherPlugin' => '^1.0']]]);
        $installed = [['name' => 'OtherPlugin', 'version' => '1.4.2']];

        $resolution = $resolver->resolve($manifest, $installed);

        $this->assertTrue($resolution->resolvable);
        $this->assertSame([], $resolution->conflicts);
    }

    public function testResolvePluginDependencyViolatingConstraintIsConflict(): void
    {
        $resolver = new DependencyResolver($this->rootPath);
        $manifest = $this->manifest(['dependencies' => ['plugins' => ['OtherPlugin' => '^2.0']]]);
        $installed = [['name' => 'OtherPlugin', 'version' => '1.4.2']];

        $resolution = $resolver->resolve($manifest, $installed);

        $this->assertFalse($resolution->resolvable);
        $this->assertCount(1, $resolution->conflicts);
        $this->assertStringContainsString('OtherPlugin v1.4.2 does not satisfy ^2.0', $resolution->conflicts[0]);
    }

    public function testResolvePluginDependencyWildcardConstraintAlwaysSatisfies(): void
    {
        $resolver = new DependencyResolver($this->rootPath);
        $manifest = $this->manifest(['dependencies' => ['plugins' => ['OtherPlugin' => '*']]]);
        $installed = [['name' => 'OtherPlugin', 'version' => '0.0.1']];

        $resolution = $resolver->resolve($manifest, $installed);

        $this->assertTrue($resolution->resolvable);
    }

    // =========================================================================
    // resolve() — Composer dependencies (delegates to checkComposerDeps())
    // =========================================================================

    public function testResolveFlagsUninstalledComposerPackage(): void
    {
        $this->writeComposerLock();
        $resolver = new DependencyResolver($this->rootPath);
        $manifest = $this->manifest(['dependencies' => ['composer' => ['symfony/mailer' => '^7.0']]]);

        $resolution = $resolver->resolve($manifest, []);

        // Composer gaps are surfaced as installable packages, not hard conflicts.
        $this->assertTrue($resolution->resolvable);
        $this->assertSame(['symfony/mailer' => '^7.0'], $resolution->composerPackages);
    }

    public function testResolveDoesNotFlagAlreadySatisfiedComposerPackage(): void
    {
        $this->writeComposerLock(packages: [['name' => 'symfony/mailer', 'version' => 'v7.2.0']]);
        $resolver = new DependencyResolver($this->rootPath);
        $manifest = $this->manifest(['dependencies' => ['composer' => ['symfony/mailer' => '^7.0']]]);

        $resolution = $resolver->resolve($manifest, []);

        $this->assertSame([], $resolution->composerPackages);
    }

    // =========================================================================
    // checkComposerDeps()
    // =========================================================================

    public function testCheckComposerDepsReturnsEmptyForEmptyInput(): void
    {
        $resolver = new DependencyResolver($this->rootPath);

        $this->assertSame([], $resolver->checkComposerDeps([]));
    }

    public function testCheckComposerDepsWithoutLockFileTreatsEverythingAsMissing(): void
    {
        $resolver = new DependencyResolver($this->rootPath);

        $result = $resolver->checkComposerDeps(['guzzlehttp/guzzle' => '^7.0']);

        $this->assertSame(['guzzlehttp/guzzle' => '^7.0'], $result);
    }

    public function testCheckComposerDepsReadsPackagesAndPackagesDevSections(): void
    {
        $this->writeComposerLock(
            packages: [['name' => 'symfony/mailer', 'version' => 'v7.2.0']],
            packagesDev: [['name' => 'phpunit/phpunit', 'version' => 'v11.5.0']],
        );
        $resolver = new DependencyResolver($this->rootPath);

        $result = $resolver->checkComposerDeps([
            'symfony/mailer' => '^7.0',
            'phpunit/phpunit' => '^11.0',
        ]);

        $this->assertSame([], $result);
    }

    public function testCheckComposerDepsFlagsVersionThatDoesNotSatisfyConstraint(): void
    {
        $this->writeComposerLock(packages: [['name' => 'symfony/mailer', 'version' => 'v6.4.0']]);
        $resolver = new DependencyResolver($this->rootPath);

        $result = $resolver->checkComposerDeps(['symfony/mailer' => '^7.0']);

        $this->assertSame(['symfony/mailer' => '^7.0'], $result);
    }

    public function testCheckComposerDepsHandlesMalformedLockFileGracefully(): void
    {
        file_put_contents($this->rootPath . '/composer.lock', 'not json');
        $resolver = new DependencyResolver($this->rootPath);

        $result = $resolver->checkComposerDeps(['symfony/mailer' => '^7.0']);

        $this->assertSame(['symfony/mailer' => '^7.0'], $result);
    }
}
