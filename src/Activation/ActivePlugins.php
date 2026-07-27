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

use Milpa\Attributes\PluginMetadata;
use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Plugin\Contracts\PluginRecord;
use Milpa\Plugin\Contracts\PluginRegistryInterface;
use Milpa\Plugin\Registry\FilePluginRegistry;

/**
 * Which plugin classes actually boot, from two sources that answer two
 * different questions.
 *
 * **What a host has** is declared in code — a plain list a developer edits and
 * reads in a diff. **What is switched on** is state, and state has to be
 * writable at runtime for an admin panel to switch anything at all. Keeping
 * them apart is what lets a panel manage plugins without ever writing PHP back
 * to disk: a page that rewrites its own source is a code-execution write
 * surface, and it breaks the moment a deploy is read-only or opcache holds the
 * old file.
 *
 * The rules, in the order they matter:
 *
 * - A declared class with no record **boots**. Adding a line to the list is
 *   still all it takes, and a host that never installed anything never grows a
 *   state file.
 * - A declared class the store says is disabled **does not boot**.
 * - A record that is installed, enabled, and whose class resolves **boots**,
 *   even though nobody declared it — that is a plugin installed at runtime.
 * - A record whose class does not resolve is skipped in silence: it is
 *   installed but not autoloadable yet, which is a composer problem and not a
 *   reason to take the whole boot down.
 */
final readonly class ActivePlugins
{
    /**
     * The effective list, ready to hand to `$config['plugins']`.
     *
     * @param list<class-string> $declared  What the host declares in code.
     * @param string             $statePath JSON file holding activation state; it need not exist.
     *
     * @return list<class-string>
     */
    public static function from(array $declared, string $statePath): array
    {
        return self::resolve($declared, new FilePluginRegistry($statePath));
    }

    /**
     * Puts activation into a container and returns the list to boot — the one
     * call a host makes, so the store the kernel booted from and the store the
     * management operations write to cannot end up being two different files.
     *
     * @param list<class-string> $declared
     * @param string             $statePath JSON file holding activation state; it need not exist.
     *
     * @return list<class-string>
     */
    public static function wire(DIContainerInterface $container, array $declared, string $statePath): array
    {
        $registry = new FilePluginRegistry($statePath);

        $container->registerService(PluginRegistryInterface::class, $registry);
        $container->registerService(DeclaredPlugins::class, new DeclaredPlugins($declared));

        return self::resolve($declared, $registry);
    }

    /**
     * The same decision against any registry — the seam a host with a
     * different store (a database, say) uses instead of {@see self::from()}.
     *
     * @param list<class-string> $declared
     *
     * @return list<class-string>
     */
    public static function resolve(array $declared, PluginRegistryInterface $registry): array
    {
        $records = [];
        foreach ($registry->installed() as $record) {
            $records[$record->name] = $record;
        }

        $active = [];
        $seen = [];

        foreach ($declared as $class) {
            $name = self::nameOf($class);
            $record = $name !== null ? ($records[$name] ?? null) : null;

            // No record is not the same as a record saying no: a host that
            // never touched activation boots exactly what it declared.
            if ($record !== null && !$record->enabled) {
                continue;
            }

            $active[] = $class;
            if ($name !== null) {
                $seen[$name] = true;
            }
        }

        foreach ($records as $name => $record) {
            if (isset($seen[$name]) || !$record->enabled) {
                continue;
            }

            $class = self::classOf($record);
            if ($class !== null) {
                $active[] = $class;
            }
        }

        return $active;
    }

    /**
     * The registry name a plugin class declares, or null when it declares no
     * metadata at all — such a class can never match a record, so it is simply
     * governed by the declaration alone.
     *
     * @param class-string $class
     */
    private static function nameOf(string $class): ?string
    {
        if (!class_exists($class)) {
            return null;
        }

        $attributes = (new \ReflectionClass($class))->getAttributes(PluginMetadata::class);

        return $attributes === [] ? null : $attributes[0]->newInstance()->name;
    }

    /**
     * The class an installed record points at, following the layout the
     * installer writes: `plugins/{Name}/{Name}.php` under `Milpa\Plugins`.
     *
     * @return class-string|null
     */
    private static function classOf(PluginRecord $record): ?string
    {
        $class = 'Milpa\\Plugins\\' . $record->name . '\\' . $record->name;

        return class_exists($class) ? $class : null;
    }
}
