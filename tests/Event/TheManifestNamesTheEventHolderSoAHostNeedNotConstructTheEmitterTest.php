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

namespace Milpa\Plugin\Tests\Event;

use Milpa\Interfaces\Event\DeclaresEvents;
use Milpa\Interfaces\Event\EventDeclaration;
use Milpa\Plugin\Event\PluginEvents;
use PHPUnit\Framework\TestCase;

/**
 * The falsifier of greenhouse decisions/0228, second slice: this package NAMES its event holder in its
 * own manifest, so a host can read the events out of `vendor/composer/installed.json` without ever
 * constructing the {@see \Milpa\Plugin\Runtime\PluginsManager} that dispatches them.
 *
 * Every assertion below measures the manifest ON DISK, never the prose about it: the file is read from
 * the package root and the class it names is resolved and called. A renamed holder, a typo in the FQCN,
 * a holder that stopped implementing the contract, or a manifest listing a class whose declarations
 * drift from the holder's, all turn this red.
 */
final class TheManifestNamesTheEventHolderSoAHostNeedNotConstructTheEmitterTest extends TestCase
{
    /** The holders this package promises a host will find in its manifest. */
    private const EXPECTED_HOLDERS = [PluginEvents::class];

    public function testTheManifestListsExactlyThisPackagesEventHolders(): void
    {
        $manifest = $this->manifest();

        $this->assertArrayHasKey('extra', $manifest, 'The manifest carries no "extra" section, so no host can find the holder.');
        $this->assertArrayHasKey('milpa', $manifest['extra'], 'The manifest carries no "extra.milpa" section.');
        $this->assertArrayHasKey('events', $manifest['extra']['milpa'], 'The manifest names no "extra.milpa.events" holders.');
        $this->assertSame(self::EXPECTED_HOLDERS, $manifest['extra']['milpa']['events'], 'The manifest names holders other than this package\'s.');
    }

    public function testEveryClassTheManifestNamesExistsAndIsAHolder(): void
    {
        foreach ($this->namedHolders() as $holder) {
            $this->assertTrue(class_exists($holder), "The manifest names «{$holder}», which no autoloader can resolve.");
            $this->assertTrue(is_a($holder, DeclaresEvents::class, true), "The manifest names «{$holder}», which does not implement " . DeclaresEvents::class . '.');
        }
    }

    public function testTheClassNamedInTheManifestDeclaresTheSameEventsTheHolderDoes(): void
    {
        $fromManifest = [];
        foreach ($this->namedHolders() as $holder) {
            /** @var list<EventDeclaration> $declarations */
            $declarations = $holder::declarations();
            foreach ($declarations as $declaration) {
                $fromManifest[] = $declaration->name;
            }
        }

        $fromHolder = array_map(static fn (EventDeclaration $d): string => $d->name, PluginEvents::declarations());

        $this->assertNotSame([], $fromHolder, 'Sanity: the holder declares nothing, so the comparison would be vacuous.');
        sort($fromManifest);
        sort($fromHolder);
        $this->assertSame($fromHolder, $fromManifest, 'The manifest reaches a different set of events than the holder declares.');
    }

    /**
     * The holder class names this package's manifest lists, read from disk.
     *
     * @return list<class-string<DeclaresEvents>>
     */
    private function namedHolders(): array
    {
        $manifest = $this->manifest();
        $named = $manifest['extra']['milpa']['events'] ?? [];
        $this->assertIsArray($named, 'extra.milpa.events must be a LIST of fully-qualified class names.');
        $this->assertNotSame([], $named, 'extra.milpa.events is empty, so a host reading it learns nothing.');

        $holders = [];
        foreach ($named as $holder) {
            $this->assertIsString($holder, 'Every entry of extra.milpa.events must be a class name.');
            /** @var class-string<DeclaresEvents> $holder */
            $holders[] = $holder;
        }

        return $holders;
    }

    /**
     * This package's own composer.json, decoded from the file the release actually ships.
     *
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        $path = \dirname(__DIR__, 2) . '/composer.json';
        $this->assertFileExists($path, 'The package manifest is not where the release ships it.');
        $raw = file_get_contents($path);
        $this->assertIsString($raw, 'The package manifest could not be read.');
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded, 'The package manifest is not a JSON object.');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
