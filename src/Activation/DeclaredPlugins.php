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

namespace Milpa\Plugin\Activation;

/**
 * The plugin classes a host declares in code, carried in the container so that
 * whatever needs them can ask for them by type.
 *
 * It exists because the list has two readers that must never disagree: the
 * kernel, which boots what {@see ActivePlugins} resolved from it, and the
 * management operations, which have to show a plugin that has no store record
 * yet — otherwise a running app reports that it has no plugins.
 */
final readonly class DeclaredPlugins
{
    /**
     * @param list<class-string> $classes
     */
    public function __construct(public array $classes = [])
    {
    }
}
