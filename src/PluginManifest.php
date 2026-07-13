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
     * The capability entries decide the emitted shape — the generator NEVER
     * invents metadata:
     *
     * - every entry a structured record → the canonical `capabilities` block
     *   (milpa-plugin.schema.json), each record validated through core's
     *   capability value objects plus the generation-time provider check
     *   (`service` present, autoloadable, implementing the declared interface);
     * - every entry a bare FQCN string → the legacy `contracts` block exactly
     *   as before, plus a warning in `$warnings` teaching how to reach canonical;
     * - a mix of both shapes → hard failure: one plugin migrates atomically.
     *
     * @param array{name?: string, version?: string, author?: string, site?: string, type?: string, provides?: list<string|array<string, mixed>>, requires?: list<string|array<string, mixed>>, suggests?: list<string|array<string, mixed>>} $metadata
     * @param string                                                                                                                                                                                                                          $namespace  Full namespace of the plugin class
     * @param string                                                                                                                                                                                                                          $entrypoint Plugin filename (e.g., "MailPlugin.php")
     * @param list<string>                                                                                                                                                                                                                    $warnings   Reset and filled with warnings the caller should surface (legacy shape emitted)
     *
     * @return array<string, mixed> The manifest data ready to be saved as JSON
     *
     * @throws \InvalidArgumentException On mixed entry shapes, malformed records, or providers that do not resolve
     */
    public static function generateFromMetadata(array $metadata, string $namespace, string $entrypoint, array &$warnings = []): array
    {
        $warnings = [];
        $pluginName = $metadata['name'] ?? 'Unknown';
        $vendorName = strtolower($pluginName);

        $entriesByKind = [
            'provides' => $metadata['provides'] ?? [],
            'requires' => $metadata['requires'] ?? [],
            'suggests' => $metadata['suggests'] ?? [],
        ];

        if (self::declaresRichRecords($entriesByKind, $pluginName)) {
            $capabilityBlock = ['capabilities' => self::canonicalCapabilities($entriesByKind, $pluginName, $warnings)];
        } else {
            $capabilityBlock = ['contracts' => $entriesByKind];
            if ($entriesByKind !== ['provides' => [], 'requires' => [], 'suggests' => []]) {
                $warnings[] = "Plugin \"{$pluginName}\" declares legacy bare-FQCN capabilities; emitted the legacy `contracts` block unchanged. "
                    . 'Enrich #[PluginMetadata] with rich capability records (id, interface, contractVersion, service) '
                    . 'to generate the canonical `capabilities` block (milpa-plugin.schema.json).';
            }
        }

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
            ...$capabilityBlock,
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
     * Decide which capability shape the metadata declares: TRUE when every
     * entry is a structured record (canonical), FALSE when every entry is a
     * bare FQCN string or the lists are empty (legacy). Mixing both shapes in
     * one plugin hard-fails — a plugin migrates atomically, because the
     * canonical `capabilities` block cannot hold bare strings and readers
     * prefer `capabilities`, silently ignoring a leftover `contracts` block.
     *
     * @param array<string, array<int|string, mixed>> $entriesByKind
     *
     * @throws \InvalidArgumentException On mixed shapes or an entry that is neither string nor record
     */
    private static function declaresRichRecords(array $entriesByKind, string $pluginName): bool
    {
        $sawRich = false;
        $sawBare = false;

        foreach ($entriesByKind as $kind => $entries) {
            foreach ($entries as $index => $entry) {
                if (is_array($entry)) {
                    $sawRich = true;
                } elseif (is_string($entry)) {
                    $sawBare = true;
                } else {
                    throw new \InvalidArgumentException(sprintf(
                        '#[PluginMetadata] %s entry #%s of plugin "%s" must be an FQCN string or a record object.',
                        $kind,
                        (string) $index,
                        $pluginName,
                    ));
                }
            }
        }

        if ($sawRich && $sawBare) {
            throw new \InvalidArgumentException(
                "Plugin \"{$pluginName}\" mixes bare-FQCN strings and rich capability records in #[PluginMetadata]. "
                . 'A generated manifest carries ONE capability shape: the canonical `capabilities` block cannot hold bare strings, '
                . 'and readers prefer `capabilities`, silently ignoring a leftover legacy `contracts` block. '
                . 'Migrate all entries of this plugin together — enrich every bare FQCN into a record '
                . '(id, interface, contractVersion, service) and regenerate.'
            );
        }

        return $sawRich;
    }

    /**
     * Build the canonical `capabilities` block from rich records — every
     * record validated through core's capability value objects, providers
     * additionally checked against the loaded codebase. `$warnings` receives
     * the omitted-constraint nudge (see the record canonicalizers).
     *
     * @param array<string, array<int|string, mixed>> $entriesByKind
     * @param list<string>                            $warnings
     *
     * @return array{provides: list<array<string, mixed>>, requires: list<array<string, mixed>>, suggests: list<array<string, mixed>>}
     *
     * @throws \InvalidArgumentException On malformed records or providers that do not resolve
     */
    private static function canonicalCapabilities(array $entriesByKind, string $pluginName, array &$warnings): array
    {
        $capabilities = ['provides' => [], 'requires' => [], 'suggests' => []];

        foreach ($entriesByKind['provides'] as $index => $record) {
            $capabilities['provides'][] = self::canonicalProvidesRecord((array) $record, $index, $pluginName);
        }
        foreach ($entriesByKind['requires'] as $index => $record) {
            $capabilities['requires'][] = self::canonicalRequiresRecord((array) $record, $index, $pluginName, $warnings);
        }
        foreach ($entriesByKind['suggests'] as $index => $record) {
            $capabilities['suggests'][] = self::canonicalSuggestsRecord((array) $record, $index, $pluginName, $warnings);
        }

        return $capabilities;
    }

    /**
     * Validate and normalize one rich `provides` record into its canonical
     * schema shape. The record routes through {@see CapabilityProvision} for
     * the shared validation, then the generation-time tightening runs: the
     * canonical schema requires `service` (the T1 adjudication pinned this
     * validation HERE, not in the value object), and the provider must resolve
     * against the loaded codebase. Optional fields (`priority`, `exclusive`)
     * are emitted only when the author declared them — never invented.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When the record is malformed or the provider does not resolve
     */
    private static function canonicalProvidesRecord(array $record, int|string $index, string $pluginName): array
    {
        self::rejectUnknownRecordKeys($record, 'provides', $index, ['id', 'interface', 'contractVersion', 'service', 'priority', 'exclusive'], $pluginName);

        $provision = CapabilityProvision::fromArray($record);

        if ($provision->service === null) {
            throw new \InvalidArgumentException(
                "Capability `provides` record \"{$provision->id}\" of plugin \"{$pluginName}\" has no `service`. "
                . 'The canonical manifest (milpa-plugin.schema.json) requires it: a provider names the concrete class '
                . 'implementing the contract, so the resolver binds the capability without guessing. '
                . 'Add `service` to the record in #[PluginMetadata] — the generator never invents metadata.'
            );
        }

        self::assertProviderResolves($provision->id, $provision->interface, $provision->service, $pluginName);

        $canonical = [
            'id' => $provision->id,
            'interface' => $provision->interface,
            'contractVersion' => $provision->contractVersion,
            'service' => $provision->service,
        ];
        if (array_key_exists('priority', $record)) {
            $canonical['priority'] = $provision->priority;
        }
        if (array_key_exists('exclusive', $record)) {
            $canonical['exclusive'] = $provision->exclusive;
        }

        return $canonical;
    }

    /**
     * Validate and normalize one rich `requires` record into its canonical
     * schema shape, via {@see CapabilityRequirement}. An omitted `constraint`
     * normalizes to the record's documented `*` (any version) — the schema
     * requires the key, so this is normalization, not invention — but the
     * generator SAYS so through `$warnings` (the T2-review nudge): a declared
     * constraint, even an explicit `*`, is the author speaking and stays
     * silent. `oneOf` is emitted only when the author declared it.
     *
     * @param array<string, mixed> $record
     * @param list<string>         $warnings
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When the record is malformed
     */
    private static function canonicalRequiresRecord(array $record, int|string $index, string $pluginName, array &$warnings): array
    {
        self::rejectUnknownRecordKeys($record, 'requires', $index, ['id', 'interface', 'constraint', 'oneOf'], $pluginName);

        $requirement = CapabilityRequirement::fromArray($record);

        if (!array_key_exists('constraint', $record)) {
            $warnings[] = self::omittedConstraintNudge('requires', $requirement->id, $pluginName);
        }

        $canonical = [
            'id' => $requirement->id,
            'interface' => $requirement->interface,
            'constraint' => $requirement->constraint,
        ];
        if (array_key_exists('oneOf', $record)) {
            $canonical['oneOf'] = $requirement->oneOf;
        }

        return $canonical;
    }

    /**
     * Validate and normalize one rich `suggests` record into its canonical
     * schema shape, via {@see CapabilitySuggestion}. An omitted `constraint`
     * normalizes to `*` like `requires` does — and nudges through `$warnings`
     * the same way; `fallback` is emitted only when the author declared a
     * non-empty one.
     *
     * @param array<string, mixed> $record
     * @param list<string>         $warnings
     *
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException When the record is malformed
     */
    private static function canonicalSuggestsRecord(array $record, int|string $index, string $pluginName, array &$warnings): array
    {
        self::rejectUnknownRecordKeys($record, 'suggests', $index, ['id', 'interface', 'constraint', 'fallback'], $pluginName);

        $suggestion = CapabilitySuggestion::fromArray($record);

        if (!array_key_exists('constraint', $record)) {
            $warnings[] = self::omittedConstraintNudge('suggests', $suggestion->id, $pluginName);
        }

        $canonical = [
            'id' => $suggestion->id,
            'interface' => $suggestion->interface,
            'constraint' => $suggestion->constraint,
        ];
        if ($suggestion->fallback !== null) {
            $canonical['fallback'] = $suggestion->fallback;
        }

        return $canonical;
    }

    /**
     * The omitted-constraint nudge (T2 review, adjudication 4): one visible line teaching that
     * the emitted `*` was a normalization the author never spoke, and how to speak it.
     */
    private static function omittedConstraintNudge(string $kind, string $id, string $pluginName): string
    {
        return "Capability `{$kind}` record \"{$id}\" of plugin \"{$pluginName}\" declares no `constraint`; "
            . 'emitted the any-version `*`. Declare an explicit constraint (e.g. ^1.0 — or `*` on purpose) '
            . 'so the record states the contract range it actually needs.';
    }

    /**
     * Refuse record keys the canonical schema does not know
     * (additionalProperties: false) — silently dropping them would lose
     * declared metadata, the write-path dual of "never invents metadata".
     *
     * @param array<string, mixed> $record
     * @param list<string>         $allowed
     *
     * @throws \InvalidArgumentException When the record carries unknown keys
     */
    private static function rejectUnknownRecordKeys(array $record, string $kind, int|string $index, array $allowed, string $pluginName): void
    {
        $unknown = array_diff(array_keys($record), $allowed);
        if ($unknown === []) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'Capability `%s` record #%s of plugin "%s" carries unknown key(s): %s. '
            . 'The canonical schema (milpa-plugin.schema.json) allows only: %s. '
            . 'The generator refuses to drop declared metadata silently — fix or remove them.',
            $kind,
            (string) $index,
            $pluginName,
            implode(', ', array_map('strval', $unknown)),
            implode(', ', $allowed),
        ));
    }

    /**
     * The generation-time provider check, mirroring
     * `Milpa\DevTools\Validators\ProviderImplementsValidator` (B5): the
     * declared interface must autoload, the service class must autoload, and
     * the service must implement/extend the interface. Runs while generating
     * so a broken manifest is never written.
     *
     * @throws \InvalidArgumentException When the provider does not resolve
     */
    private static function assertProviderResolves(string $id, string $interface, string $service, string $pluginName): void
    {
        $interfaceFqcn = ltrim($interface, '\\');
        $serviceFqcn = ltrim($service, '\\');

        if (!interface_exists($interfaceFqcn) && !class_exists($interfaceFqcn) && !trait_exists($interfaceFqcn)) {
            throw new \InvalidArgumentException(
                "Capability `provides` record \"{$id}\" of plugin \"{$pluginName}\" declares interface \"{$interface}\", "
                . 'but no such type autoloads. Fix the FQCN in #[PluginMetadata] before generating.'
            );
        }

        if (!class_exists($serviceFqcn)) {
            throw new \InvalidArgumentException(
                "Capability `provides` record \"{$id}\" of plugin \"{$pluginName}\" declares service \"{$service}\", "
                . 'but that class does not autoload. The generator refuses to write a manifest that cannot boot — '
                . 'fix the FQCN or create the class.'
            );
        }

        if (!is_a($serviceFqcn, $interfaceFqcn, true)) {
            throw new \InvalidArgumentException(
                "Capability `provides` record \"{$id}\" of plugin \"{$pluginName}\": service \"{$service}\" "
                . "does not implement \"{$interface}\". A provider's service must implement its declared contract."
            );
        }
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
     * Convert to the metadata-array shape (`Plugins::$plugins`) that some consumers
     * still read instead of the typed accessors below. The capability lists carry the
     * RAW declarations of whichever shape the manifest speaks — canonical
     * `capabilities.*` records or legacy `contracts.*` bare FQCNs (see
     * {@see rawCapabilityRecords()}) — because this array feeds the host boot path,
     * whose ingestion ({@see \Milpa\Attributes\PluginMetadata} -> AttributeLoader)
     * accepts both shapes. Reading only `contracts.*` here would blind the boot to
     * every canonical manifest (the Cosecha T2-Major finding).
     */
    public function toMetadataArray(): array
    {
        return [
            'name' => $this->getDisplayName(),
            'version' => (string) $this->version,
            'author' => $this->data['authors'][0]['name'] ?? 'Unknown',
            'site' => $this->data['homepage'] ?? $this->data['authors'][0]['email'] ?? '',
            'type' => $this->getType(),
            'provides' => $this->rawCapabilityRecords('provides'),
            'requires' => $this->rawCapabilityRecords('requires'),
            'suggests' => $this->rawCapabilityRecords('suggests'),
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
