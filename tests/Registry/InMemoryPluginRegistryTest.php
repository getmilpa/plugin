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
use Milpa\Plugin\Registry\InMemoryPluginRegistry;
use Milpa\Plugin\Testing\PluginRegistryContractTestBase;

final class InMemoryPluginRegistryTest extends PluginRegistryContractTestBase
{
    protected function makeRegistry(): PluginRegistryInterface
    {
        return new InMemoryPluginRegistry();
    }

    public function testInvalidateActivationCacheIsANoOp(): void
    {
        $registry = new InMemoryPluginRegistry();
        $registry->register($this->record('MailPlugin', enabled: true));

        $registry->invalidateActivationCache();

        $this->assertSame(['MailPlugin'], $registry->enabledNames());
    }
}
