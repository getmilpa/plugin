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
 * Readonly snapshot of one plugin's registration state.
 *
 * The fields mirror exactly what the host's activation store persists today
 * (name/version/author/site/type/installed/enabled plus the remote-install
 * trio source/installedVersion/installedAt and the composer ledger).
 */
final readonly class PluginRecord
{
    /**
     * @param string                  $name             Plugin name (unique registry key), e.g. "MailPlugin".
     * @param string                  $version          Declared plugin version (semver string).
     * @param string                  $author           Author from the plugin metadata.
     * @param string                  $site             Author/plugin site URL from the metadata.
     * @param string                  $type             Plugin type (e.g. "Web", "CLI", "Mixed", "Service").
     * @param bool                    $installed        Whether the plugin is installed.
     * @param bool                    $enabled          Whether the plugin is enabled (boots).
     * @param string|null             $source           Install source: null/"local" for local, "github:owner/repo" for remote.
     * @param string|null             $installedVersion Exact version installed from the remote source.
     * @param \DateTimeImmutable|null $installedAt      When the remote install happened.
     * @param list<string>|null       $composerDeps     Composer package specs installed for this plugin.
     */
    public function __construct(
        public string $name,
        public string $version,
        public string $author,
        public string $site,
        public string $type,
        public bool $installed,
        public bool $enabled,
        public ?string $source = null,
        public ?string $installedVersion = null,
        public ?\DateTimeImmutable $installedAt = null,
        public ?array $composerDeps = null,
    ) {
    }

    /** The same record with the enabled flag replaced. */
    public function withEnabled(bool $enabled): self
    {
        return new self(
            name: $this->name,
            version: $this->version,
            author: $this->author,
            site: $this->site,
            type: $this->type,
            installed: $this->installed,
            enabled: $enabled,
            source: $this->source,
            installedVersion: $this->installedVersion,
            installedAt: $this->installedAt,
            composerDeps: $this->composerDeps,
        );
    }
}
