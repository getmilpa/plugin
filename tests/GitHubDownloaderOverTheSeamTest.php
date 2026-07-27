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
use Milpa\ValueObjects\SemanticVersion;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Everything above the transport, now that there is a seam to reach it through.
 *
 * Version listing, constraint resolution, the zipball download and its
 * fallback tag were all unreachable while the only way in was
 * `file_get_contents()`. The existing suite says so in as many words and
 * defers the fix; this is that fix being used.
 */
final class GitHubDownloaderOverTheSeamTest extends TestCase
{
    /**
     * @param array<string, array{int, string}> $routes URL substring => [status, body]
     */
    private function downloader(array $routes, ?string $token = null): GitHubDownloader
    {
        return new GitHubDownloader($token, new RoutingHttpClient($routes), new Psr17Factory());
    }

    /**
     * @param list<array<string, mixed>> $payload
     */
    private function json(array $payload): string
    {
        return (string) json_encode($payload);
    }

    // ---- listVersions ---------------------------------------------------------

    public function testReleasesAreListedNewestFirstAndDraftsAreLeftOut(): void
    {
        $downloader = $this->downloader(['/releases' => [200, $this->json([
            ['tag_name' => 'v1.2.0'],
            ['tag_name' => 'v2.0.0', 'draft' => true],
            ['tag_name' => 'v1.10.0'],
            ['tag_name' => 'no-es-semver'],
            'ni siquiera es un objeto',
        ])]]);

        $versions = array_map('strval', $downloader->listVersions('acme', 'plugin'));

        self::assertSame(['1.10.0', '1.2.0'], $versions, 'Newest first, by semver order — not by string order.');
    }

    public function testWithNoReleasesItFallsBackToTags(): void
    {
        // A repo that tags but never cuts releases is the common case for small
        // plugins; without the fallback it would look like it has no versions.
        $downloader = $this->downloader([
            '/releases' => [200, $this->json([])],
            '/tags' => [200, $this->json([
                ['name' => 'v0.3.0'],
                ['name' => 'sin-version'],
                ['name' => 'v0.4.0'],
            ])],
        ]);

        $versions = array_map('strval', $downloader->listVersions('acme', 'plugin'));

        self::assertSame(['0.4.0', '0.3.0'], $versions);
    }

    public function testAnErrorStatusYieldsNoVersionsRatherThanGarbage(): void
    {
        $downloader = $this->downloader([
            '/releases' => [404, 'Not Found'],
            '/tags' => [404, 'Not Found'],
        ]);

        self::assertSame([], $downloader->listVersions('acme', 'no-existe'));
    }

    public function testATransportThatCannotReachGitHubYieldsNoVersions(): void
    {
        $downloader = new GitHubDownloader(null, new UnreachableHttpClient(), new Psr17Factory());

        self::assertSame([], $downloader->listVersions('acme', 'plugin'));
    }

    // ---- resolveVersion --------------------------------------------------------

    public function testWithNoConstraintItPicksTheNewestStableNotTheNewestOverall(): void
    {
        // A prerelease is newer and must not be handed to someone who did not
        // ask for one.
        $downloader = $this->downloader(['/releases' => [200, $this->json([
            ['tag_name' => 'v2.0.0-beta.1'],
            ['tag_name' => 'v1.4.0'],
        ])]]);

        self::assertSame('1.4.0', (string) $downloader->resolveVersion('acme', 'plugin'));
    }

    public function testWithOnlyPrereleasesItReturnsTheNewestOfThem(): void
    {
        // Nothing stable exists: refusing outright would leave the plugin
        // uninstallable, so the newest prerelease is the honest answer.
        $downloader = $this->downloader(['/releases' => [200, $this->json([
            ['tag_name' => 'v0.2.0-rc.1'],
            ['tag_name' => 'v0.1.0-beta.1'],
        ])]]);

        self::assertSame('0.2.0-rc.1', (string) $downloader->resolveVersion('acme', 'plugin'));
    }

    public function testAConstraintPicksTheHighestVersionThatSatisfiesIt(): void
    {
        $downloader = $this->downloader(['/releases' => [200, $this->json([
            ['tag_name' => 'v3.0.0'],
            ['tag_name' => 'v2.4.0'],
            ['tag_name' => 'v2.1.0'],
        ])]]);

        self::assertSame('2.4.0', (string) $downloader->resolveVersion('acme', 'plugin', '^2.0'));
    }

    public function testAWildcardConstraintIsTreatedAsNoConstraint(): void
    {
        $downloader = $this->downloader(['/releases' => [200, $this->json([
            ['tag_name' => 'v1.1.0'],
        ])]]);

        self::assertSame('1.1.0', (string) $downloader->resolveVersion('acme', 'plugin', '*'));
    }

    public function testARepoWithNoVersionsAtAllIsNamedInTheError(): void
    {
        $downloader = $this->downloader([
            '/releases' => [200, $this->json([])],
            '/tags' => [200, $this->json([])],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No releases found for acme/plugin');

        $downloader->resolveVersion('acme', 'plugin');
    }

    public function testAConstraintNothingSatisfiesIsNamedInTheError(): void
    {
        $downloader = $this->downloader(['/releases' => [200, $this->json([
            ['tag_name' => 'v1.0.0'],
        ])]]);

        $this->expectException(\RuntimeException::class);

        $downloader->resolveVersion('acme', 'plugin', '^9.0');
    }

    // ---- getRepoInfo ------------------------------------------------------------

    public function testRepoInfoComesBackDecoded(): void
    {
        $downloader = $this->downloader(['repos/acme/plugin' => [200, (string) json_encode([
            'description' => 'Un plugin',
            'default_branch' => 'main',
            'stargazers_count' => 7,
        ])]]);

        $info = $downloader->getRepoInfo('acme', 'plugin');

        self::assertNotNull($info);
        self::assertSame('main', $info['default_branch']);
    }

    public function testRepoInfoForSomethingThatIsNotThereIsNull(): void
    {
        $downloader = $this->downloader(['repos/acme/plugin' => [404, 'Not Found']]);

        self::assertNull($downloader->getRepoInfo('acme', 'plugin'));
    }

    public function testABodyThatIsNotJsonIsTreatedAsNoAnswer(): void
    {
        $downloader = $this->downloader(['repos/acme/plugin' => [200, '<html>rate limited</html>']]);

        self::assertNull($downloader->getRepoInfo('acme', 'plugin'));
    }

    // ---- a token on the wire -------------------------------------------------------

    public function testATokenTravelsAsABearerHeader(): void
    {
        $client = new RoutingHttpClient(['/releases' => [200, $this->json([])], '/tags' => [200, $this->json([])]]);
        $downloader = new GitHubDownloader('t0k3n', $client, new Psr17Factory());

        $downloader->listVersions('acme', 'plugin');

        self::assertNotNull($client->lastRequest);
        self::assertSame('Bearer t0k3n', $client->lastRequest->getHeaderLine('Authorization'));
    }

    public function testWithNoTokenNoAuthorizationHeaderIsSent(): void
    {
        // An empty Authorization header is worse than none: GitHub answers 401
        // instead of serving the public repo anonymously.
        $client = new RoutingHttpClient(['/releases' => [200, $this->json([])], '/tags' => [200, $this->json([])]]);
        $downloader = new GitHubDownloader('', $client, new Psr17Factory());

        $downloader->listVersions('acme', 'plugin');

        self::assertNotNull($client->lastRequest);
        self::assertFalse($client->lastRequest->hasHeader('Authorization'));
    }

    // ---- download ---------------------------------------------------------------------

    public function testAZipballIsDownloadedAndExtractedToItsTopLevelDirectory(): void
    {
        $zip = $this->zipWithATopLevelDirectory();
        $downloader = $this->downloader(['zipball/v1.0.0' => [200, $zip]]);

        $path = $downloader->download('acme', 'plugin', new SemanticVersion(1, 0, 0));

        try {
            self::assertDirectoryExists($path);
            self::assertFileExists($path . '/milpa.json');
        } finally {
            $downloader->cleanup(\dirname($path));
        }
    }

    public function testATagWithoutTheVPrefixIsTriedBeforeGivingUp(): void
    {
        // Not every repo tags with a leading v. Failing on the first miss would
        // make half of GitHub uninstallable.
        $zip = $this->zipWithATopLevelDirectory();
        $downloader = $this->downloader([
            'zipball/v1.0.0' => [404, ''],
            'zipball/1.0.0' => [200, $zip],
        ]);

        $path = $downloader->download('acme', 'plugin', new SemanticVersion(1, 0, 0));

        try {
            self::assertDirectoryExists($path);
        } finally {
            $downloader->cleanup(\dirname($path));
        }
    }

    public function testWhenNeitherTagExistsTheErrorNamesTheRepoAndVersion(): void
    {
        $downloader = $this->downloader([
            'zipball/v1.0.0' => [404, ''],
            'zipball/1.0.0' => [404, ''],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to download acme/plugin 1.0.0');

        $downloader->download('acme', 'plugin', new SemanticVersion(1, 0, 0));
    }

    public function testAnArchiveThatIsNotAZipIsReportedWithTheLibrarysErrorCode(): void
    {
        $downloader = $this->downloader(['zipball/v1.0.0' => [200, 'no soy un zip']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to extract plugin archive');

        $downloader->download('acme', 'plugin', new SemanticVersion(1, 0, 0));
    }

    public function testAZipWithNoTopLevelDirectoryIsRejected(): void
    {
        // GitHub zipballs always carry one "owner-repo-hash/" directory. A zip
        // with only loose files is not one, and extracting it would leave the
        // installer pointing at a temp dir instead of a plugin.
        $downloader = $this->downloader(['zipball/v1.0.0' => [200, $this->zipWithLooseFilesOnly()]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Extracted archive is empty or has unexpected structure');

        $downloader->download('acme', 'plugin', new SemanticVersion(1, 0, 0));
    }

    private function zipWithATopLevelDirectory(): string
    {
        return $this->zip(['acme-plugin-abc123/milpa.json' => '{"name":"acme/plugin"}']);
    }

    private function zipWithLooseFilesOnly(): string
    {
        return $this->zip(['milpa.json' => '{"name":"acme/plugin"}']);
    }

    /**
     * @param array<string, string> $entries
     */
    private function zip(array $entries): string
    {
        $path = sys_get_temp_dir() . '/milpa-plugin-test-' . uniqid('', true) . '.zip';
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::CREATE) === true);
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();

        $bytes = (string) file_get_contents($path);
        unlink($path);

        return $bytes;
    }
}

/**
 * A PSR-18 client that answers from a table keyed by URL substring, and
 * remembers the last request so a test can look at what went on the wire.
 */
final class RoutingHttpClient implements ClientInterface
{
    public ?RequestInterface $lastRequest = null;

    /**
     * @param array<string, array{int, string}> $routes URL substring => [status, body]
     */
    public function __construct(private readonly array $routes = [])
    {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->lastRequest = $request;
        $url = (string) $request->getUri();

        foreach ($this->routes as $fragment => [$status, $body]) {
            if (str_contains($url, $fragment)) {
                return new Response($status, [], $body);
            }
        }

        return new Response(404, [], 'Not Found');
    }
}

/**
 * A client that cannot reach the network at all — the PSR-18 equivalent of
 * `file_get_contents()` returning false.
 */
final class UnreachableHttpClient implements ClientInterface
{
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw new class ('sin red') extends \RuntimeException implements ClientExceptionInterface {};
    }
}
