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

namespace Milpa\Plugin\Tests\Fixtures;

use Milpa\Plugin\Contracts\PluginDownloaderInterface;
use Milpa\Plugin\GitHubDownloader;
use Milpa\ValueObjects\SemanticVersion;

/**
 * Network-free source of plugins: serves a scripted version and a pre-built
 * extract dir.
 *
 * It speaks the port instead of inheriting the GitHub client — which is the
 * point of the port. Coordinate parsing is pure (no I/O), so it delegates to
 * the real implementation rather than re-stating the grammar here: a double
 * that parsed coordinates its own way would pass cases the installer fails.
 */
final class FakeDownloader implements PluginDownloaderInterface
{
    private readonly GitHubDownloader $parser;

    public function __construct(private readonly string $extractDir, private readonly string $version = '1.0.0')
    {
        $this->parser = new GitHubDownloader(null);
    }

    /**
     * Delegated to the real parser — the same grammar the installer will meet.
     *
     * @return array{owner: string, repo: string, constraint: ?string}
     */
    public function parseSource(string $source): array
    {
        return $this->parser->parseSource($source);
    }

    /**
     * Always resolves to the version this double was constructed with.
     */
    public function resolveVersion(string $owner, string $repo, ?string $constraint = null): SemanticVersion
    {
        return SemanticVersion::parse($this->version);
    }

    /**
     * Returns the pre-built extract dir the test set up — no network, no zip.
     */
    public function download(string $owner, string $repo, SemanticVersion $version): string
    {
        return $this->extractDir;
    }

    /**
     * A real recursive delete, exactly like the GitHub client's.
     *
     * A no-op here would be the comfortable choice, but it would also hide
     * every behaviour that depends on cleanup actually removing something —
     * chiefly that an update REPLACES the plugin directory instead of merging
     * the new version over the old one.
     */
    public function cleanup(string $path): void
    {
        if (!is_dir($path)) {
            if (file_exists($path)) {
                unlink($path);
            }

            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
