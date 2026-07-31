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

namespace Milpa\Plugin\Tests\Fixtures;

use Milpa\Attributes\PluginMetadata;
use Milpa\Interfaces\Di\DIContainerInterface;

/**
 * Statically-declared fixture plugin for the API suite: replaces the host's
 * AntiScannerPlugin as the "real class with real metadata" test subject.
 */
#[PluginMetadata(
    version: '1.0.0',
    author: 'Acme',
    site: 'https://teamx.agency',
    name: 'ApiFixture',
    type: 'Service',
)]
class ApiFixturePlugin
{
    public bool $booted = false;

    public function __construct(private readonly DIContainerInterface $container)
    {
    }

    /** Boot hook the manager invokes. */
    public function boot(): void
    {
        $this->booted = true;
    }
}
