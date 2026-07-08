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

use Milpa\Plugin\ContractResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ContractResolverTest extends TestCase
{
    private ContractResolver $resolver;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->resolver = new ContractResolver($this->logger);
    }

    public function testValidateWithNoRequirements(): void
    {
        $plugins = [
            ['name' => 'PluginA', 'provides' => ['serviceA']],
            ['name' => 'PluginB', 'provides' => ['serviceB']],
        ];

        // Should not throw
        $this->resolver->validate($plugins);
        $this->assertTrue(true);
    }

    public function testValidateWithSatisfiedRequirements(): void
    {
        $plugins = [
            ['name' => 'PluginA', 'provides' => ['database']],
            ['name' => 'PluginB', 'requires' => ['database']],
        ];

        // Should not throw
        $this->resolver->validate($plugins);
        $this->assertTrue(true);
    }

    public function testValidateThrowsOnMissingRequirement(): void
    {
        $plugins = [
            ['name' => 'PluginA', 'requires' => ['missing_service']],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("requires 'missing_service'");

        $this->resolver->validate($plugins);
    }

    public function testValidateWarnsOnMissingSuggested(): void
    {
        $plugins = [
            ['name' => 'PluginA', 'suggests' => ['optional_feature']],
        ];

        $this->logger->expects($this->once())
            ->method('debug')
            ->with($this->stringContains("suggests 'optional_feature'"));

        $this->resolver->validate($plugins);
    }

    public function testValidateWithSatisfiedSuggested(): void
    {
        $plugins = [
            ['name' => 'PluginA', 'provides' => ['cache']],
            ['name' => 'PluginB', 'suggests' => ['cache']],
        ];

        $this->logger->expects($this->never())->method('debug');

        $this->resolver->validate($plugins);
    }

    public function testGetLoadOrderNoDependencies(): void
    {
        $plugins = [
            ['name' => 'PluginA', 'class' => 'ClassA'],
            ['name' => 'PluginB', 'class' => 'ClassB'],
            ['name' => 'PluginC', 'class' => 'ClassC'],
        ];

        $sorted = $this->resolver->getLoadOrder($plugins);

        $this->assertCount(3, $sorted);
    }

    public function testGetLoadOrderWithDependencies(): void
    {
        $plugins = [
            ['name' => 'PluginC', 'class' => 'ClassC', 'requires' => ['serviceB']],
            ['name' => 'PluginA', 'class' => 'ClassA', 'provides' => ['serviceA']],
            ['name' => 'PluginB', 'class' => 'ClassB', 'provides' => ['serviceB'], 'requires' => ['serviceA']],
        ];

        $sorted = $this->resolver->getLoadOrder($plugins);

        $names = array_column($sorted, 'name');

        // A must come before B (B requires A)
        $this->assertLessThan(array_search('PluginB', $names), array_search('PluginA', $names));
        // B must come before C (C requires B)
        $this->assertLessThan(array_search('PluginC', $names), array_search('PluginB', $names));
    }

    public function testGetLoadOrderDetectsCycle(): void
    {
        $plugins = [
            ['name' => 'PluginA', 'class' => 'ClassA', 'provides' => ['serviceA'], 'requires' => ['serviceB']],
            ['name' => 'PluginB', 'class' => 'ClassB', 'provides' => ['serviceB'], 'requires' => ['serviceA']],
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Circular dependency');

        $this->resolver->getLoadOrder($plugins);
    }

    public function testGetLoadOrderWithDiamondDependency(): void
    {
        $plugins = [
            ['name' => 'D', 'class' => 'ClassD', 'requires' => ['serviceB', 'serviceC']],
            ['name' => 'A', 'class' => 'ClassA', 'provides' => ['serviceA']],
            ['name' => 'B', 'class' => 'ClassB', 'provides' => ['serviceB'], 'requires' => ['serviceA']],
            ['name' => 'C', 'class' => 'ClassC', 'provides' => ['serviceC'], 'requires' => ['serviceA']],
        ];

        $sorted = $this->resolver->getLoadOrder($plugins);

        $names = array_column($sorted, 'name');

        // A must be first
        $this->assertEquals('A', $names[0]);
        // D must be last
        $this->assertEquals('D', $names[3]);
        // B and C must be between A and D
        $posB = array_search('B', $names);
        $posC = array_search('C', $names);
        $this->assertGreaterThan(0, $posB);
        $this->assertLessThan(3, $posB);
        $this->assertGreaterThan(0, $posC);
        $this->assertLessThan(3, $posC);
    }

    public function testGetAvailableContracts(): void
    {
        $plugins = [
            ['name' => 'PluginA', 'provides' => ['database', 'cache']],
            ['name' => 'PluginB', 'provides' => ['auth']],
        ];

        $contracts = $this->resolver->getAvailableContracts($plugins);

        $this->assertArrayHasKey('database', $contracts);
        $this->assertArrayHasKey('cache', $contracts);
        $this->assertArrayHasKey('auth', $contracts);
        $this->assertEquals('PluginA', $contracts['database']);
        $this->assertEquals('PluginB', $contracts['auth']);
    }

    public function testGetAvailableContractsEmpty(): void
    {
        $plugins = [
            ['name' => 'PluginA'],
            ['name' => 'PluginB'],
        ];

        $contracts = $this->resolver->getAvailableContracts($plugins);

        $this->assertEmpty($contracts);
    }

    public function testResolverWithoutLogger(): void
    {
        $resolver = new ContractResolver();

        $plugins = [
            ['name' => 'PluginA', 'suggests' => ['missing']],
        ];

        // Should not throw even without logger
        $resolver->validate($plugins);
        $this->assertTrue(true);
    }

    public function testValidateWithEmptyPlugins(): void
    {
        $this->resolver->validate([]);
        $this->assertTrue(true);
    }

    public function testGetLoadOrderWithEmptyPlugins(): void
    {
        $sorted = $this->resolver->getLoadOrder([]);

        $this->assertEmpty($sorted);
    }

    public function testSelfDependencyIsIgnored(): void
    {
        $plugins = [
            ['name' => 'PluginA', 'class' => 'ClassA', 'provides' => ['serviceA'], 'requires' => ['serviceA']],
        ];

        $sorted = $this->resolver->getLoadOrder($plugins);

        $this->assertCount(1, $sorted);
    }

    public function testMultiplePluginsWithSameContract(): void
    {
        $plugins = [
            ['name' => 'PluginA', 'provides' => ['database']],
            ['name' => 'PluginB', 'provides' => ['database']], // Overwrites
        ];

        $contracts = $this->resolver->getAvailableContracts($plugins);

        // Last one wins
        $this->assertEquals('PluginB', $contracts['database']);
    }
}
