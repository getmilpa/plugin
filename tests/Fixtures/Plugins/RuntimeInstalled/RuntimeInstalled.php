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

namespace Milpa\Plugins\RuntimeInstalled;

use Milpa\Attributes\PluginMetadata;

/**
 * Stands in for a plugin the installer put on disk at runtime: it lives at the
 * exact coordinate the installer writes — `Milpa\Plugins\{Name}\{Name}` — which
 * is how activation finds a class nobody declared.
 */
#[PluginMetadata(
    version: '1.0.0',
    author: 'Acme',
    site: 'https://example.com',
    name: 'RuntimeInstalled',
    type: 'Service',
)]
final class RuntimeInstalled
{
}
