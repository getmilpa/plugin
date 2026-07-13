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

namespace Milpa\Plugin\Tests;

use Milpa\Plugin\GitHubDownloader;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure, network-free surface of {@see GitHubDownloader}: source
 * parsing and temp-directory cleanup.
 *
 * `listVersions()`, `resolveVersion()`, `download()` and `getRepoInfo()` all
 * drive the GitHub API over `file_get_contents()` with an inline
 * `stream_context_create()` — a global, non-injectable I/O call, so they
 * cannot be exercised here without hitting the network. See the package
 * report's "Fricciones DX" section: a PSR-18 `ClientInterface` seam (the
 * pattern already used by `milpa/mcp-client` and `milpa/ai-gateway`) is the
 * natural fix for a future wave.
 */
final class GitHubDownloaderTest extends TestCase
{
    // =========================================================================
    // parseSource()
    // =========================================================================

    public function testParseSourceOwnerRepo(): void
    {
        $downloader = new GitHubDownloader();

        $result = $downloader->parseSource('acme/mail-plugin');

        $this->assertSame(['owner' => 'acme', 'repo' => 'mail-plugin', 'constraint' => null], $result);
    }

    public function testParseSourceWithConstraint(): void
    {
        $downloader = new GitHubDownloader();

        $result = $downloader->parseSource('acme/mail-plugin:^2.0');

        $this->assertSame('acme', $result['owner']);
        $this->assertSame('mail-plugin', $result['repo']);
        $this->assertSame('^2.0', $result['constraint']);
    }

    public function testParseSourceHttpsGitHubUrl(): void
    {
        $downloader = new GitHubDownloader();

        $result = $downloader->parseSource('https://github.com/acme/mail-plugin');

        $this->assertSame('acme', $result['owner']);
        $this->assertSame('mail-plugin', $result['repo']);
        $this->assertNull($result['constraint']);
    }

    public function testParseSourceHttpGitHubUrl(): void
    {
        $downloader = new GitHubDownloader();

        $result = $downloader->parseSource('http://github.com/acme/mail-plugin');

        $this->assertSame('acme', $result['owner']);
        $this->assertSame('mail-plugin', $result['repo']);
    }

    public function testParseSourceStripsDotGitSuffix(): void
    {
        $downloader = new GitHubDownloader();

        $result = $downloader->parseSource('https://github.com/acme/mail-plugin.git');

        $this->assertSame('mail-plugin', $result['repo']);
    }

    public function testParseSourceThrowsOnSingleSegment(): void
    {
        $downloader = new GitHubDownloader();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid source format: 'acme'");

        $downloader->parseSource('acme');
    }

    public function testParseSourceThrowsOnTooManySegments(): void
    {
        $downloader = new GitHubDownloader();

        $this->expectException(\InvalidArgumentException::class);

        $downloader->parseSource('acme/mail-plugin/extra');
    }

    public function testParseSourceThrowsOnEmptyOwner(): void
    {
        $downloader = new GitHubDownloader();

        $this->expectException(\InvalidArgumentException::class);

        $downloader->parseSource('/mail-plugin');
    }

    public function testParseSourceThrowsOnEmptyRepo(): void
    {
        $downloader = new GitHubDownloader();

        $this->expectException(\InvalidArgumentException::class);

        $downloader->parseSource('acme/');
    }

    // =========================================================================
    // cleanup()
    // =========================================================================

    public function testCleanupRemovesDirectoryRecursively(): void
    {
        $downloader = new GitHubDownloader();
        $dir = sys_get_temp_dir() . '/milpa_plugin_test_' . uniqid();
        mkdir($dir . '/nested', 0755, true);
        file_put_contents($dir . '/file.txt', 'x');
        file_put_contents($dir . '/nested/file.txt', 'y');

        $downloader->cleanup($dir);

        $this->assertDirectoryDoesNotExist($dir);
    }

    public function testCleanupRemovesPlainFile(): void
    {
        $downloader = new GitHubDownloader();
        $file = sys_get_temp_dir() . '/milpa_plugin_test_' . uniqid() . '.zip';
        file_put_contents($file, 'x');

        $downloader->cleanup($file);

        $this->assertFileDoesNotExist($file);
    }

    public function testCleanupOnNonExistentPathIsNoOp(): void
    {
        $downloader = new GitHubDownloader();

        $downloader->cleanup(sys_get_temp_dir() . '/milpa_plugin_never_existed_' . uniqid());

        $this->addToAssertionCount(1); // no exception thrown
    }
}
