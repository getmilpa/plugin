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

namespace Milpa\Plugin\Contracts;

use Psr\Log\LoggerInterface;

/**
 * Everything a plugin migration may touch while running.
 *
 * The connection is deliberately typed `object`: the host injects its real
 * database handle (e.g. a Doctrine DBAL Connection) and the migration casts
 * to what it expects — the package itself never depends on any driver.
 */
final readonly class MigrationContext
{
    public function __construct(
        public object $connection,
        public LoggerInterface $logger,
    ) {
    }
}
