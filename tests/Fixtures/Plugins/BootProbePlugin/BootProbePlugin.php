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

namespace Milpa\Plugins\BootProbePlugin;

use Milpa\Attributes\PluginMetadata;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Interfaces\Plugin\PluginInterface;

/**
 * A real plugin on disk, at the coordinate the scanner derives from its path, that does nothing
 * but boot — so a test can drive the manager's whole boot path and watch every lifecycle event
 * it dispatches along the way.
 */
#[PluginMetadata(
    version: '1.0.0',
    author: 'Acme',
    site: 'https://teamx.agency',
    name: 'BootProbe',
    type: 'Service',
)]
final class BootProbePlugin implements PluginInterface
{
    public function __construct(?DIContainerInterface $container = null)
    {
    }

    public function boot(): void
    {
    }

    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function enable(): void
    {
    }

    public function disable(): void
    {
    }
}
