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
 * Outcome of a composer-require run.
 */
final readonly class ComposerResult
{
    /**
     * @param bool         $success       True only when EVERY requested package installed.
     * @param list<string> $installed     Package specs that DID install (also populated on failure).
     * @param string|null  $failedPackage The package spec whose install failed (null on success).
     * @param string       $output        Combined stdout+stderr of the run(s).
     */
    public function __construct(
        public bool $success,
        public array $installed,
        public ?string $failedPackage,
        public string $output,
    ) {
    }
}
