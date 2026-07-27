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
 * Runs `composer require` on behalf of the plugin installer.
 *
 * The package never shells out by itself — the host provides the process
 * implementation. A failed package STOPS the run (no further packages are
 * attempted); the caller aborts and rolls back the installation.
 */
interface ComposerRunnerInterface
{
    /**
     * Require the given packages inside $workingDir, stopping at the first failure.
     *
     * @param string                $workingDir Directory whose composer.json receives the requires.
     * @param array<string, string> $packages   Package name => version constraint.
     */
    public function requirePackages(string $workingDir, array $packages): ComposerResult;
}
