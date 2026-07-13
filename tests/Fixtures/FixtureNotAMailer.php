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

/**
 * A real, autoloadable class that does NOT implement
 * {@see FixtureMailerInterface} — the "does not implement" path of the
 * generation-time provider check.
 */
final class FixtureNotAMailer
{
}
