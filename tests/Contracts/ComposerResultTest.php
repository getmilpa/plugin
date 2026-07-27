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

use Milpa\Plugin\Contracts\ComposerResult;
use PHPUnit\Framework\TestCase;

/**
 * ComposerResult reports a composer-require run honestly: on failure it names
 * the package that broke the run and keeps what had installed before it —
 * the caller decides how to roll back.
 */
final class ComposerResultTest extends TestCase
{
    public function testSuccessCarriesEveryInstalledSpec(): void
    {
        $result = new ComposerResult(
            success: true,
            installed: ['acme/sdk:^2.0', 'acme/tools:^1.0'],
            failedPackage: null,
            output: "ok\n",
        );

        $this->assertTrue($result->success);
        $this->assertSame(['acme/sdk:^2.0', 'acme/tools:^1.0'], $result->installed);
        $this->assertNull($result->failedPackage);
    }

    public function testFailureNamesTheOffenderAndKeepsPriorInstalls(): void
    {
        $result = new ComposerResult(
            success: false,
            installed: ['acme/sdk:^2.0'],
            failedPackage: 'boom/broken:^1.0',
            output: "Could not find package boom/broken\n",
        );

        $this->assertFalse($result->success);
        $this->assertSame('boom/broken:^1.0', $result->failedPackage);
        $this->assertSame(['acme/sdk:^2.0'], $result->installed);
        $this->assertStringContainsString('boom/broken', $result->output);
    }
}
