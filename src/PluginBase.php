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

namespace Milpa\Plugin;

use Milpa\Interfaces\Di\DIContainerInterface;
use Milpa\Plugin\Contracts\PluginSchemaManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Convenience base class for Milpa plugins: container plumbing (service
 * registration/lookup, logging) plus the entity-schema pair.
 *
 * The schema pair discovers the REAL fully-qualified class names by reading
 * each file's namespace/class declaration — never guessing a namespace from
 * the directory layout — and delegates the work to the host's
 * {@see PluginSchemaManagerInterface} adapter resolved from the container.
 */
abstract class PluginBase
{
    protected DIContainerInterface $container;

    public function __construct(DIContainerInterface $container)
    {
        $this->container = $container;
    }

    /**
     * Register multiple services at once.
     *
     * @param array<string, object> $services Map of service ID => instance
     */
    protected function registerServices(array $services): void
    {
        foreach ($services as $id => $instance) {
            $this->registerService($id, $instance);
        }
    }

    /**
     * Get the DI container — the seam a subclass reaches through when the
     * helpers below are not enough.
     */
    protected function getContainer(): DIContainerInterface
    {
        return $this->container;
    }

    /** Register one service instance under the given identifier. */
    protected function registerService(string $name, object $class): void
    {
        $this->container->registerService($name, $class);
    }

    /**
     * Get a service from the container.
     *
     * @throws \Psr\Container\NotFoundExceptionInterface If the service cannot be resolved.
     */
    protected function getService(string $name): object
    {
        return $this->container->get($name);
    }

    /** Get a service or null when not available (optional dependencies). */
    protected function tryGetService(string $name): ?object
    {
        return $this->container->tryGet($name);
    }

    /** Debug-log through the container's logger when one is registered. */
    protected function log(string $message): void
    {
        if ($this->container->has(LoggerInterface::class)) {
            $logger = $this->container->get(LoggerInterface::class);
            if ($logger instanceof LoggerInterface) {
                $logger->debug($message);
            }
        }
    }

    /**
     * Create the database schema for every entity class found under $path.
     *
     * @param string      $path Directory holding the plugin's entity files.
     * @param object|null $em   Legacy parameter (the old base took the
     *                          EntityManager here); ignored — the schema
     *                          port does the work. Kept so existing call
     *                          sites need no edit.
     */
    protected function createEntitiesFromPath(string $path, ?object $em = null): void
    {
        $manager = $this->schemaManager();
        if ($manager === null) {
            return;
        }

        $classes = $this->discoverEntityClasses($path);
        if ($classes === []) {
            return;
        }

        $manager->createSchemaFor($classes);
    }

    /**
     * Drop the database schema for every entity class found under $path.
     *
     * @param string      $path Directory holding the plugin's entity files.
     * @param object|null $em   Legacy parameter, ignored (see createEntitiesFromPath).
     */
    protected function removeEntitiesFromPath(string $path, ?object $em = null): void
    {
        $manager = $this->schemaManager();
        if ($manager === null) {
            return;
        }

        $classes = $this->discoverEntityClasses($path);
        if ($classes === []) {
            return;
        }

        $manager->dropSchemaFor($classes);
    }

    private function schemaManager(): ?PluginSchemaManagerInterface
    {
        $manager = $this->container->tryGet(PluginSchemaManagerInterface::class);
        if ($manager instanceof PluginSchemaManagerInterface) {
            return $manager;
        }

        $this->log('PluginSchemaManagerInterface is not registered in the container; skipping entity schema work.');

        return null;
    }

    /**
     * Discover the REAL FQCN declared in each PHP file under $path.
     *
     * @return list<class-string>
     */
    private function discoverEntityClasses(string $path): array
    {
        $classes = [];
        foreach (glob($path . '/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            if (!preg_match('/^namespace\s+([^;]+);/m', $source, $ns)
                || !preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*class\s+(\w+)/m', $source, $class)
            ) {
                $this->log('No class declaration found in ' . basename($file) . '; skipping.');
                continue;
            }

            $fqcn = trim($ns[1]) . '\\' . $class[1];
            if (!class_exists($fqcn)) {
                $this->log("Entity class {$fqcn} declared in " . basename($file) . ' is not loadable; skipping.');
                continue;
            }

            $classes[] = $fqcn;
        }

        return $classes;
    }
}
