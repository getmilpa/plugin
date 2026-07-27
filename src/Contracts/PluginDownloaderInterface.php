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

use Milpa\ValueObjects\SemanticVersion;

/**
 * Where a plugin's source code comes from.
 *
 * The installer needs four things from a source of plugins: read a coordinate,
 * pick a version, put the files somewhere on disk, and clean up after itself.
 * It does not need to know that any of it happens over GitHub — and once that
 * is true, an installer can be driven by a catalog, a private mirror, or a
 * test double without any of them pretending to be a GitHub client.
 *
 * {@see \Milpa\Plugin\GitHubDownloader} is the implementation that ships with
 * the framework.
 */
interface PluginDownloaderInterface
{
    /**
     * Reads a coordinate into its parts: `owner/repo`, `owner/repo:^2.0`, or a
     * full URL. Pure — no network, no disk.
     *
     * @return array{owner: string, repo: string, constraint: ?string}
     *
     * @throws \InvalidArgumentException when the coordinate cannot be read
     */
    public function parseSource(string $source): array;

    /**
     * The highest published version satisfying the constraint, or the latest
     * one when no constraint is given.
     *
     * @throws \RuntimeException when no version satisfies the constraint
     */
    public function resolveVersion(string $owner, string $repo, ?string $constraint = null): SemanticVersion;

    /**
     * Places that version's files on disk and returns the directory holding
     * them.
     *
     * The returned directory MUST be nested inside a scratch directory this
     * downloader owns and nothing else uses — never a path whose parent holds
     * anything else. The installer cleans up by removing the returned path's
     * PARENT, so an implementation that returns, say, `/tmp/plugin` hands
     * `/tmp` to a recursive delete. It is the one invariant a third-party
     * source of plugins has to get right.
     *
     * @throws \RuntimeException when the download or the extraction fails
     */
    public function download(string $owner, string $repo, SemanticVersion $version): string;

    /**
     * Recursively removes a directory: either one produced by
     * {@see self::download()} (or its owned parent), or a plugin directory the
     * installer is replacing.
     *
     * Never throws — cleanup runs on failure paths, where a second exception
     * would bury the first.
     */
    public function cleanup(string $path): void;
}
