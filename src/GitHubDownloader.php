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

use Milpa\ValueObjects\SemanticVersion;

/**
 * Downloads plugin releases from GitHub.
 *
 * Uses GitHub REST API v3 (no auth required for public repos).
 * Optionally uses GITHUB_TOKEN env var for private repos or rate limits.
 */
final class GitHubDownloader
{
    private ?string $token;

    public function __construct(?string $token = null)
    {
        $this->token = $token ?? ($_ENV['GITHUB_TOKEN'] ?? null);
    }

    /**
     * Parse a source string into owner, repo, and optional version constraint.
     *
     * Formats:
     *   "acme/mail-plugin"                      → owner=acme, repo=mail-plugin, constraint=null
     *   "acme/mail-plugin:^2.0"                 → owner=acme, repo=mail-plugin, constraint=^2.0
     *   "https://github.com/acme/mail-plugin"   → owner=acme, repo=mail-plugin, constraint=null
     *
     * @return array{owner: string, repo: string, constraint: ?string}
     *
     * @throws \InvalidArgumentException
     */
    public function parseSource(string $source): array
    {
        $constraint = null;

        // Handle full GitHub URL
        if (str_starts_with($source, 'https://github.com/') || str_starts_with($source, 'http://github.com/')) {
            $path = parse_url($source, PHP_URL_PATH);
            if ($path === null || $path === false) {
                throw new \InvalidArgumentException("Invalid GitHub URL: {$source}");
            }
            $source = ltrim($path, '/');
            // Remove .git suffix if present
            $source = preg_replace('/\.git$/', '', $source) ?? $source;
        }

        // Split version constraint if present: "owner/repo:^2.0"
        if (str_contains($source, ':')) {
            [$source, $constraint] = explode(':', $source, 2);
        }

        // Split owner/repo
        $parts = explode('/', $source);
        if (count($parts) !== 2 || empty($parts[0]) || empty($parts[1])) {
            throw new \InvalidArgumentException(
                "Invalid source format: '{$source}'. Expected 'owner/repo' or 'https://github.com/owner/repo'"
            );
        }

        return [
            'owner' => $parts[0],
            'repo' => $parts[1],
            'constraint' => $constraint,
        ];
    }

    /**
     * List available versions (releases + tags) from GitHub.
     * Filters to valid semver only.
     *
     * @return array<SemanticVersion> Sorted descending (newest first)
     */
    public function listVersions(string $owner, string $repo): array
    {
        $versions = [];

        // Try releases first
        $releases = $this->apiGet("repos/{$owner}/{$repo}/releases");
        if (is_array($releases)) {
            foreach ($releases as $release) {
                if (!is_array($release) || ($release['draft'] ?? false)) {
                    continue;
                }
                $tag = $release['tag_name'] ?? '';
                $version = SemanticVersion::tryParse($tag);
                if ($version !== null) {
                    $versions[] = $version;
                }
            }
        }

        // If no releases, try tags
        if (empty($versions)) {
            $tags = $this->apiGet("repos/{$owner}/{$repo}/tags");
            if (is_array($tags)) {
                foreach ($tags as $tag) {
                    if (!is_array($tag)) {
                        continue;
                    }
                    $tagName = $tag['name'] ?? '';
                    $version = SemanticVersion::tryParse($tagName);
                    if ($version !== null) {
                        $versions[] = $version;
                    }
                }
            }
        }

        // Sort descending (newest first)
        usort($versions, fn (SemanticVersion $a, SemanticVersion $b) => $b->compareTo($a));

        return $versions;
    }

    /**
     * Find the best matching version for a constraint.
     *
     * @param string|null $constraint Semver constraint (e.g., "^2.0", ">=1.5"). If null, returns latest stable.
     *
     * @throws \RuntimeException If no matching version found
     */
    public function resolveVersion(string $owner, string $repo, ?string $constraint = null): SemanticVersion
    {
        $versions = $this->listVersions($owner, $repo);

        if (empty($versions)) {
            throw new \RuntimeException("No releases found for {$owner}/{$repo}");
        }

        // If no constraint, return the latest stable version
        if ($constraint === null || $constraint === '' || $constraint === '*') {
            foreach ($versions as $version) {
                if ($version->isStable()) {
                    return $version;
                }
            }
            // If no stable version, return the latest
            return $versions[0];
        }

        // Find the best (highest) version that satisfies the constraint
        foreach ($versions as $version) {
            if ($version->satisfies($constraint)) {
                return $version;
            }
        }

        throw new \RuntimeException(
            "No version of {$owner}/{$repo} satisfies constraint '{$constraint}'. " .
            "Available: " . implode(', ', array_map(fn ($v) => (string) $v, array_slice($versions, 0, 5)))
        );
    }

    /**
     * Download and extract a specific version to a temporary directory.
     *
     * @return string Path to the extracted plugin directory
     *
     * @throws \RuntimeException On download or extraction failure
     */
    public function download(string $owner, string $repo, SemanticVersion $version): string
    {
        $tag = "v{$version}";

        // Download zipball
        $zipUrl = "https://api.github.com/repos/{$owner}/{$repo}/zipball/{$tag}";
        $tempDir = sys_get_temp_dir() . '/milpa_plugin_' . uniqid();
        $zipPath = $tempDir . '.zip';

        mkdir($tempDir, 0755, true);

        $zipContent = $this->httpGet($zipUrl);
        if ($zipContent === null || strlen($zipContent) === 0) {
            // Try without 'v' prefix
            $tag = (string) $version;
            $zipUrl = "https://api.github.com/repos/{$owner}/{$repo}/zipball/{$tag}";
            $zipContent = $this->httpGet($zipUrl);

            if ($zipContent === null || strlen($zipContent) === 0) {
                $this->cleanup($tempDir);
                throw new \RuntimeException("Failed to download {$owner}/{$repo} {$version}");
            }
        }

        file_put_contents($zipPath, $zipContent);

        // Extract zip
        $zip = new \ZipArchive();
        $result = $zip->open($zipPath);
        if ($result !== true) {
            unlink($zipPath);
            $this->cleanup($tempDir);
            throw new \RuntimeException("Failed to extract plugin archive (error code: {$result})");
        }

        $zip->extractTo($tempDir);
        $zip->close();
        unlink($zipPath);

        // GitHub zipballs contain a single top-level directory (e.g., "owner-repo-hash/")
        $extracted = glob($tempDir . '/*', GLOB_ONLYDIR);
        if (empty($extracted)) {
            $this->cleanup($tempDir);
            throw new \RuntimeException("Extracted archive is empty or has unexpected structure");
        }

        return $extracted[0];
    }

    /**
     * Get repository info from GitHub API.
     *
     * @return array{description: string, default_branch: string, stargazers_count: int}|null
     */
    public function getRepoInfo(string $owner, string $repo): ?array
    {
        $data = $this->apiGet("repos/{$owner}/{$repo}");
        return is_array($data) ? $data : null;
    }

    /**
     * Clean up a temporary directory recursively.
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
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }

    /**
     * Make a GitHub API GET request.
     *
     * @return array<mixed>|null Decoded JSON response
     */
    private function apiGet(string $endpoint): ?array
    {
        $url = "https://api.github.com/{$endpoint}";

        $headers = [
            'Accept: application/vnd.github+json',
            'User-Agent: Milpa-Framework/2.0',
            'X-GitHub-Api-Version: 2022-11-28',
        ];

        if ($this->token !== null && $this->token !== '') {
            $headers[] = "Authorization: Bearer {$this->token}";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $http_response_header = [];
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }

        // Check for HTTP errors via response headers
        $statusCode = $this->extractStatusCode($http_response_header);
        if ($statusCode >= 400) {
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Make an HTTP GET request with redirect following (for zipball downloads).
     */
    private function httpGet(string $url): ?string
    {
        $headers = [
            'User-Agent: Milpa-Framework/2.0',
        ];

        if ($this->token !== null && $this->token !== '') {
            $headers[] = "Authorization: Bearer {$this->token}";
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => implode("\r\n", $headers),
                'timeout' => 120,
                'follow_location' => true,
                'max_redirects' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $http_response_header = [];
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return null;
        }

        $statusCode = $this->extractStatusCode($http_response_header);
        if ($statusCode >= 400) {
            return null;
        }

        return $response;
    }

    /**
     * Extract HTTP status code from response headers.
     *
     * @param array<string> $headers
     */
    private function extractStatusCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('/^HTTP\/[\d.]+ (\d+)/', $header, $matches)) {
                return (int) $matches[1];
            }
        }
        return 0;
    }
}
