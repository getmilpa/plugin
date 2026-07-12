<?php

/**
 * This file is part of Milpa Plugin — the GitHub-native plugin distribution
 * core of the Milpa PHP framework.
 *
 * (c) TeamX Agency — https://teamx.agency <hola@teamx.agency>
 *
 * @license Apache-2.0
 *
 * @link    https://github.com/getmilpa/plugin
 */

declare(strict_types=1);

namespace Milpa\Plugin;

use Psr\Log\LoggerInterface;

/**
 * ContractResolver - Validates and orders plugins by their contracts.
 *
 * Handles:
 * - Validation of required dependencies (fail-fast on missing)
 * - Warning for missing suggested dependencies
 * - Topological sorting for proper load order
 */
class ContractResolver
{
    private ?LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Validate that all required dependencies are satisfied.
     *
     * @param array<array{name?: string, provides?: array<string>, requires?: array<string>, suggests?: array<string>}> $plugins
     *
     * @throws \RuntimeException if a required dependency is missing
     *
     * @deprecated Superseded by milpa/resolver: {@see \Milpa\Resolver\Engine\GraphResolver::resolve()}
     *             reaches the same verdict through the report — a required capability with no
     *             provider lands in `missing[]` and blocks the graph (status `blocked`), with a
     *             learnable error attached
     *             ({@see \Milpa\Resolver\Report\ResolutionReport::firstLearnableLine()}) instead
     *             of a bare RuntimeException.
     */
    public function validate(array $plugins): void
    {
        $availableContracts = $this->buildContractMap($plugins);

        foreach ($plugins as $plugin) {
            $pluginName = $plugin['name'] ?? 'Unknown';
            $requires = $plugin['requires'] ?? [];
            $suggests = $plugin['suggests'] ?? [];

            // Check hard dependencies
            foreach ($requires as $required) {
                if (!isset($availableContracts[$required])) {
                    throw new \RuntimeException(
                        "Plugin '{$pluginName}' requires '{$required}' but no plugin provides it. " .
                        "Available contracts: " . implode(', ', array_keys($availableContracts))
                    );
                }
            }

            // Warn about missing soft dependencies
            foreach ($suggests as $suggested) {
                if (!isset($availableContracts[$suggested])) {
                    $this->log("Plugin '{$pluginName}' suggests '{$suggested}' which is not provided (optional)");
                }
            }
        }
    }

    /**
     * Sort plugins by dependency order (topological sort).
     * Plugins with no dependencies come first.
     *
     * @param array<array{name: string, class: string, provides?: array<string>, requires?: array<string>}> $plugins
     *
     * @return array<array{name: string, class: string, provides?: array<string>, requires?: array<string>}>
     *
     * @deprecated Superseded by milpa/resolver: the same Kahn pass lives in
     *             {@see \Milpa\Resolver\Engine\GraphResolver} (semantics replicated exactly) and
     *             its verdict travels as the report's `loadOrder[]`
     *             ({@see \Milpa\Resolver\Report\ResolutionReport}) — the SAME resolution that
     *             gates the graph also orders it. A dependency cycle becomes a learnable
     *             `MILPA_DEPENDENCY_CYCLE` conflict on the report instead of a RuntimeException.
     */
    public function getLoadOrder(array $plugins): array
    {
        $contractToPlugin = $this->buildContractToPluginMap($plugins);
        $pluginByName = [];
        foreach ($plugins as $plugin) {
            $pluginByName[$plugin['name']] = $plugin;
        }

        // Build dependency graph: plugin name -> array of plugin names it depends on
        $graph = [];
        $inDegree = [];

        foreach ($plugins as $plugin) {
            $name = $plugin['name'];
            $graph[$name] = [];
            $inDegree[$name] = 0;
        }

        foreach ($plugins as $plugin) {
            $name = $plugin['name'];
            $requires = $plugin['requires'] ?? [];

            foreach ($requires as $required) {
                if (isset($contractToPlugin[$required])) {
                    $dependsOn = $contractToPlugin[$required];
                    if ($dependsOn !== $name) { // Avoid self-dependency
                        $graph[$dependsOn][] = $name;
                        $inDegree[$name]++;
                    }
                }
            }
        }

        // Kahn's algorithm for topological sort
        $queue = [];
        foreach ($inDegree as $name => $degree) {
            if ($degree === 0) {
                $queue[] = $name;
            }
        }

        $sorted = [];
        while (!empty($queue)) {
            $current = array_shift($queue);
            $sorted[] = $pluginByName[$current];

            foreach ($graph[$current] as $dependent) {
                $inDegree[$dependent]--;
                if ($inDegree[$dependent] === 0) {
                    $queue[] = $dependent;
                }
            }
        }

        // Check for cycles
        if (count($sorted) !== count($plugins)) {
            $missing = array_diff(array_keys($pluginByName), array_column($sorted, 'name'));
            throw new \RuntimeException(
                "Circular dependency detected among plugins: " . implode(', ', $missing)
            );
        }

        return $sorted;
    }

    /**
     * Get all available contracts from plugins.
     *
     * @param array<array{name?: string, provides?: array<string>}> $plugins
     *
     * @return array<string, string> Map of contract => providing plugin name
     */
    public function getAvailableContracts(array $plugins): array
    {
        return $this->buildContractMap($plugins);
    }

    /**
     * Build a map of contract => plugin name.
     *
     * @param array<array{name?: string, provides?: array<string>}> $plugins
     *
     * @return array<string, string>
     */
    private function buildContractMap(array $plugins): array
    {
        $map = [];
        foreach ($plugins as $plugin) {
            $pluginName = $plugin['name'] ?? 'Unknown';
            $provides = $plugin['provides'] ?? [];

            foreach ($provides as $contract) {
                $map[$contract] = $pluginName;
            }
        }
        return $map;
    }

    /**
     * Build a map of contract => plugin name (for dependency resolution).
     *
     * @param array<array{name?: string, provides?: array<string>}> $plugins
     *
     * @return array<string, string>
     */
    private function buildContractToPluginMap(array $plugins): array
    {
        return $this->buildContractMap($plugins);
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            $this->logger->debug("[ContractResolver] $message");
        }
    }
}
