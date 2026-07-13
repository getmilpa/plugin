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
 * A real service that DOES implement {@see FixtureMailerInterface} — the happy
 * path of the generation-time provider check.
 */
final class FixtureMailer implements FixtureMailerInterface
{
    /**
     * Deliver a message body (fixture behavior — never called).
     */
    public function send(string $body): void
    {
    }
}
