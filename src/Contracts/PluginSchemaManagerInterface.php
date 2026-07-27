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
 * Creates/drops the database schema for a plugin's entity classes.
 *
 * Takes EXPLICIT class names — never a path with a guessed namespace. The
 * implementation resolves dependency order (foreign keys) itself and fails
 * loudly on real errors; creating schema for classes whose tables already
 * exist is a no-op for those classes, and dropping schema for classes whose
 * tables are absent is likewise a no-op (idempotent both ways). Real errors
 * — an unknown class, a failing connection — always throw.
 */
interface PluginSchemaManagerInterface
{
    /**
     * Create the database schema for a plugin's entity classes.
     *
     * @param list<class-string> $entityClasses
     */
    public function createSchemaFor(array $entityClasses): void;

    /**
     * Drop the database schema for a plugin's entity classes.
     *
     * @param list<class-string> $entityClasses
     */
    public function dropSchemaFor(array $entityClasses): void;
}
