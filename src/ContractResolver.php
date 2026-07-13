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

use Psr\Log\LoggerInterface;

/**
 * ContractResolver - Validates and orders plugins by their contracts.
 *
 * Handles:
 * - Validation of required dependencies (fail-fast on missing)
 * - Warning for missing suggested dependencies
 * - Topological sorting for proper load order
 *
 * Capability entries come in BOTH real shapes: legacy bare FQCN strings (matched verbatim, exactly
 * as before) and canonical records — a `provides` record registers under both its `id` and its
 * `interface`; a `requires`/`suggests` record is satisfied by its `id`, its `interface`, or any
 * `oneOf` alternative (the same identity posture core's CapabilityGraphChecker adjudicated in T1).
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
     * @param array<array{name?: string, provides?: array<int, string|array<string, mixed>>, requires?: array<int, string|array<string, mixed>>, suggests?: array<int, string|array<string, mixed>>}> $plugins
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
                if ($this->providerFor($required, $availableContracts) === null) {
                    throw new \RuntimeException(
                        "Plugin '{$pluginName}' requires '{$this->labelOf($required)}' but no plugin provides it. " .
                        "Available contracts: " . implode(', ', array_keys($availableContracts))
                    );
                }
            }

            // Warn about missing soft dependencies
            foreach ($suggests as $suggested) {
                if ($this->providerFor($suggested, $availableContracts) === null) {
                    $this->log("Plugin '{$pluginName}' suggests '{$this->labelOf($suggested)}' which is not provided (optional)");
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
                $dependsOn = $this->providerFor($required, $contractToPlugin);
                if ($dependsOn !== null && $dependsOn !== $name) { // Avoid self-dependency
                    $graph[$dependsOn][] = $name;
                    $inDegree[$name]++;
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
     * Build a map of contract identity => plugin name. A bare FQCN registers verbatim (the exact
     * legacy behavior); a canonical record registers under BOTH its `id` and its `interface`, so
     * requirers of either shape find it.
     *
     * @param array<array{name?: string, provides?: array<int, string|array<string, mixed>>}> $plugins
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
                foreach ($this->identitiesOf($contract) as $identity) {
                    $map[$identity] = $pluginName;
                }
            }
        }
        return $map;
    }

    /**
     * Build a map of contract => plugin name (for dependency resolution).
     *
     * @param array<array{name?: string, provides?: array<int, string|array<string, mixed>>}> $plugins
     *
     * @return array<string, string>
     */
    private function buildContractToPluginMap(array $plugins): array
    {
        return $this->buildContractMap($plugins);
    }

    /**
     * The identities one capability entry answers to: a bare FQCN string is its own (single,
     * verbatim) identity; a record answers to its `id` and its `interface`.
     *
     * @return list<string>
     */
    private function identitiesOf(mixed $entry): array
    {
        if (is_string($entry)) {
            return $entry === '' ? [] : [$entry];
        }

        if (is_array($entry)) {
            $identities = [];
            foreach (['id', 'interface'] as $key) {
                $value = $entry[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $identities[] = trim($value);
                }
            }

            return $identities;
        }

        return [];
    }

    /**
     * Resolve the plugin that satisfies one requirement entry, or null when nobody does. A record
     * requirement also tries its `oneOf` alternatives, mirroring the resolver's matching.
     *
     * @param array<string, string> $contractMap
     */
    private function providerFor(mixed $requirement, array $contractMap): ?string
    {
        $candidates = $this->identitiesOf($requirement);
        if (is_array($requirement)) {
            foreach ((array) ($requirement['oneOf'] ?? []) as $alternative) {
                if (is_string($alternative) && trim($alternative) !== '') {
                    $candidates[] = trim($alternative);
                }
            }
        }

        foreach ($candidates as $candidate) {
            if (isset($contractMap[$candidate])) {
                return $contractMap[$candidate];
            }
        }

        return null;
    }

    /**
     * The display identity of one capability entry — the string itself, or a record's `id`
     * (falling back to its `interface`) — for error and log messages.
     */
    private function labelOf(mixed $entry): string
    {
        if (is_string($entry)) {
            return $entry;
        }

        if (is_array($entry)) {
            foreach (['id', 'interface'] as $key) {
                $value = $entry[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    return trim($value);
                }
            }
        }

        return get_debug_type($entry);
    }

    private function log(string $message): void
    {
        if ($this->logger) {
            $this->logger->debug("[ContractResolver] $message");
        }
    }
}
