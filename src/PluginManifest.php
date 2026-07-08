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

use Milpa\Interfaces\Plugin\PluginManifestInterface;
use Milpa\ValueObjects\Capability\CapabilityProvision;
use Milpa\ValueObjects\Capability\CapabilityRequirement;
use Milpa\ValueObjects\Capability\CapabilitySuggestion;
use Milpa\ValueObjects\SemanticVersion;

/**
 * Reads and validates a plugin's milpa.json manifest file.
 *
 * This is the primary source of plugin metadata when milpa.json exists.
 * Falls through to #[PluginMetadata] attributes when milpa.json is absent
 * (handled by Plugins::getMetadata()).
 */
final class PluginManifest implements PluginManifestInterface
{
    /** @var array<string, mixed> */
    private readonly array $data;
    private readonly SemanticVersion $version;

    /**
     * @param array<string, mixed> $data Raw JSON data
     */
    private function __construct(array $data, SemanticVersion $version)
    {
        $this->data = $data;
        $this->version = $version;
    }

    /**
     * Create a manifest from a milpa.json file path.
     */
    public static function fromPath(string $manifestPath): self
    {
        if (!file_exists($manifestPath)) {
            throw new \InvalidArgumentException("Manifest not found: {$manifestPath}");
        }

        $json = file_get_contents($manifestPath);
        if ($json === false) {
            throw new \InvalidArgumentException("Cannot read manifest: {$manifestPath}");
        }

        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) {
            throw new \InvalidArgumentException("Invalid JSON in manifest: {$manifestPath}");
        }

        $version = SemanticVersion::parse($data['version'] ?? '0.0.0');

        return new self($data, $version);
    }

    /**
     * Create a manifest from an array (useful for scaffolding and testing).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $version = SemanticVersion::parse($data['version'] ?? '0.0.0');
        return new self($data, $version);
    }

    /**
     * Generate a milpa.json manifest from existing #[PluginMetadata] attributes.
     *
     * @param array{name?: string, version?: string, author?: string, site?: string, type?: string, provides?: array<string>, requires?: array<string>, suggests?: array<string>} $metadata
     * @param string                                                                                                                                                              $namespace  Full namespace of the plugin class
     * @param string                                                                                                                                                              $entrypoint Plugin filename (e.g., "MailPlugin.php")
     *
     * @return array<string, mixed> The manifest data ready to be saved as JSON
     */
    public static function generateFromMetadata(array $metadata, string $namespace, string $entrypoint): array
    {
        $pluginName = $metadata['name'] ?? 'Unknown';
        $vendorName = strtolower($pluginName);

        return [
            'name' => "milpa/{$vendorName}",
            'displayName' => $pluginName,
            'description' => '',
            'version' => $metadata['version'] ?? '1.0.0',
            'type' => $metadata['type'] ?? 'Mixed',
            'license' => 'MIT',
            'authors' => [
                [
                    'name' => $metadata['author'] ?? 'Unknown',
                    'email' => '',
                ],
            ],
            'milpa' => [
                'min-version' => '2.0.0',
                'php-version' => '>=8.2',
            ],
            'contracts' => [
                'provides' => $metadata['provides'] ?? [],
                'requires' => $metadata['requires'] ?? [],
                'suggests' => $metadata['suggests'] ?? [],
            ],
            'dependencies' => [
                'plugins' => (object) [],
                'composer' => (object) [],
            ],
            'entrypoint' => $entrypoint,
            'namespace' => $namespace,
            'migrations' => [
                'directory' => 'Migrations',
            ],
            'env-vars' => [],
        ];
    }

    /**
     * Validate the manifest. Throws on invalid data.
     *
     * @throws \InvalidArgumentException If required fields are missing or invalid
     */
    public function validate(): void
    {
        $required = ['name', 'version', 'entrypoint', 'namespace'];
        foreach ($required as $field) {
            if (empty($this->data[$field])) {
                throw new \InvalidArgumentException("milpa.json missing required field: {$field}");
            }
        }

        // Validate version is valid semver (already parsed in constructor, but re-validate)
        SemanticVersion::parse($this->data['version']);

        // Validate type if present
        $validTypes = ['Web', 'CLI', 'Mixed', 'Service'];
        $type = $this->data['type'] ?? 'Mixed';
        if (!in_array($type, $validTypes, true)) {
            throw new \InvalidArgumentException(
                "Invalid plugin type '{$type}'. Must be one of: " . implode(', ', $validTypes)
            );
        }

        // Validate PHP version constraint if present
        $phpVersion = $this->getPhpVersion();
        if ($phpVersion !== null && !$this->phpVersionSatisfies($phpVersion)) {
            throw new \InvalidArgumentException(
                "Plugin requires PHP {$phpVersion}, current is " . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION
            );
        }
    }

    /**
     * Convert to the legacy metadata-array shape (`Plugins::$plugins`) that some
     * consumers still read instead of the typed accessors below.
     */
    public function toMetadataArray(): array
    {
        return [
            'name' => $this->getDisplayName(),
            'version' => (string) $this->version,
            'author' => $this->data['authors'][0]['name'] ?? 'Unknown',
            'site' => $this->data['homepage'] ?? $this->data['authors'][0]['email'] ?? '',
            'type' => $this->getType(),
            'provides' => $this->getProvides(),
            'requires' => $this->getRequires(),
            'suggests' => $this->getSuggests(),
        ];
    }

    /**
     * Vendor/package name (e.g., "acme/mail-plugin").
     */
    public function getName(): string
    {
        return $this->data['name'] ?? '';
    }

    /**
     * Human-readable display name (e.g., "Mail Plugin").
     */
    public function getDisplayName(): string
    {
        return $this->data['displayName'] ?? $this->extractDisplayName();
    }

    /**
     * Short human-readable summary of what the plugin does.
     */
    public function getDescription(): string
    {
        return $this->data['description'] ?? '';
    }

    public function getVersion(): SemanticVersion
    {
        return $this->version;
    }

    /**
     * Plugin type: Web, CLI, Mixed, Service.
     */
    public function getType(): string
    {
        return $this->data['type'] ?? 'Mixed';
    }

    /**
     * PHP namespace (e.g., "Acme\Plugins\ExamplePlugin").
     */
    public function getNamespace(): string
    {
        return $this->data['namespace'] ?? '';
    }

    /**
     * Main plugin file relative to plugin directory (e.g., "ExamplePlugin.php").
     */
    public function getEntrypoint(): string
    {
        return $this->data['entrypoint'] ?? '';
    }

    /**
     * The interfaces/services this plugin provides to the capability system.
     */
    public function getProvides(): array
    {
        return $this->data['contracts']['provides'] ?? [];
    }

    /**
     * The interfaces/services this plugin cannot boot without.
     */
    public function getRequires(): array
    {
        return $this->data['contracts']['requires'] ?? [];
    }

    /**
     * The interfaces/services this plugin can use if available but does not
     * strictly need.
     */
    public function getSuggests(): array
    {
        return $this->data['contracts']['suggests'] ?? [];
    }

    /**
     * Typed `provides` capability records (D7 wiring seam). Reads the canonical
     * `capabilities.provides` key, falling back to legacy `contracts.provides`,
     * and accepts both record arrays and legacy bare-FQCN strings.
     *
     * @return list<CapabilityProvision>
     */
    public function getProvidedCapabilities(): array
    {
        $out = [];
        foreach ($this->rawCapabilityRecords('provides') as $record) {
            $out[] = CapabilityProvision::parse($record);
        }

        return $out;
    }

    /**
     * Typed `requires` capability records (D7 wiring seam).
     *
     * @return list<CapabilityRequirement>
     */
    public function getRequiredCapabilities(): array
    {
        $out = [];
        foreach ($this->rawCapabilityRecords('requires') as $record) {
            $out[] = CapabilityRequirement::parse($record);
        }

        return $out;
    }

    /**
     * Typed `suggests` capability records (D7 wiring seam).
     *
     * @return list<CapabilitySuggestion>
     */
    public function getSuggestedCapabilities(): array
    {
        $out = [];
        foreach ($this->rawCapabilityRecords('suggests') as $record) {
            $out[] = CapabilitySuggestion::parse($record);
        }

        return $out;
    }

    /**
     * Raw capability declarations for a kind, preferring the canonical
     * `capabilities.<kind>` over the legacy `contracts.<kind>`. Only string
     * (bare-FQCN) or array (record) entries are kept.
     *
     * @return list<string|array<string, mixed>>
     */
    private function rawCapabilityRecords(string $kind): array
    {
        $source = null;

        $capabilities = $this->data['capabilities'] ?? null;
        if (is_array($capabilities) && isset($capabilities[$kind]) && is_array($capabilities[$kind])) {
            $source = $capabilities[$kind];
        } else {
            $contracts = $this->data['contracts'] ?? null;
            if (is_array($contracts) && isset($contracts[$kind]) && is_array($contracts[$kind])) {
                $source = $contracts[$kind];
            }
        }

        if ($source === null) {
            return [];
        }

        $records = [];
        foreach ($source as $record) {
            if (is_string($record) || is_array($record)) {
                $records[] = $record;
            }
        }

        return $records;
    }

    /**
     * Composer packages this plugin depends on, beyond the framework itself.
     */
    public function getComposerDependencies(): array
    {
        $deps = $this->data['dependencies']['composer'] ?? [];
        return is_array($deps) ? $deps : [];
    }

    /**
     * Other Milpa plugins this plugin depends on.
     */
    public function getPluginDependencies(): array
    {
        $deps = $this->data['dependencies']['plugins'] ?? [];
        return is_array($deps) ? $deps : [];
    }

    /**
     * The minimum Milpa framework version this plugin requires, or null if
     * unconstrained.
     */
    public function getMinMilpaVersion(): ?string
    {
        return $this->data['milpa']['min-version'] ?? null;
    }

    /**
     * The PHP version constraint this plugin requires (e.g. ">=8.2"), or null
     * if unconstrained.
     */
    public function getPhpVersion(): ?string
    {
        return $this->data['milpa']['php-version'] ?? null;
    }

    /**
     * Environment variable names the plugin expects to be set.
     */
    public function getEnvVars(): array
    {
        return $this->data['env-vars'] ?? [];
    }

    /**
     * Migrations directory name relative to plugin root (e.g., "Migrations").
     */
    public function getMigrationsDirectory(): ?string
    {
        return $this->data['migrations']['directory'] ?? null;
    }

    /**
     * The plugin's declared authors.
     */
    public function getAuthors(): array
    {
        return $this->data['authors'] ?? [];
    }

    public function getRawData(): array
    {
        return $this->data;
    }

    /**
     * Serialize the manifest data to JSON string for writing to disk.
     */
    public function toJson(): string
    {
        return json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * Extract a display name from the vendor/package name.
     * "acme/mail-plugin" → "MailPlugin"
     */
    private function extractDisplayName(): string
    {
        $name = $this->data['name'] ?? 'Unknown';
        $parts = explode('/', $name);
        $package = end($parts);

        // mail-plugin → MailPlugin
        return str_replace(' ', '', ucwords(str_replace('-', ' ', $package)));
    }

    /**
     * Check if the current PHP version satisfies the constraint.
     * Supports: ">=8.2", ">=8.2.0", "^8.2"
     */
    private function phpVersionSatisfies(string $constraint): bool
    {
        $currentPhp = SemanticVersion::parse(
            PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '.' . PHP_RELEASE_VERSION
        );

        return $currentPhp->satisfies($constraint);
    }
}
