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

/**
 * One row of the migration ledger: which plugin ran which version, when.
 */
final readonly class ExecutedMigration
{
    public function __construct(
        public string $pluginName,
        public string $version,
        public ?string $description,
        public \DateTimeImmutable $executedAt,
    ) {
    }
}
