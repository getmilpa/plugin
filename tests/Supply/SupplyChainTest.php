<?php

declare(strict_types=1);

namespace Milpa\Plugin\Tests\Supply;

use Milpa\Plugin\Supply\ClassificationResolver;
use Milpa\Plugin\Supply\ControlAttestation;
use Milpa\Plugin\Supply\PackageCapabilityClaim;
use Milpa\Plugin\Supply\PackageState;
use PHPUnit\Framework\TestCase;

/**
 * The supply chain: what a package says about itself buys it nothing.
 *
 * Each test pins one way the classification could come from the interested party — the defect class
 * GOV-00 names, moved one layer down: not the actor choosing its own controls, but the actor
 * **installing the code that declares them**.
 */
final class SupplyChainTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private function convenientClaim(string $digest = 'sha256:AAA'): PackageCapabilityClaim
    {
        // The worst case: an operation that sends email declaring itself harmless.
        return new PackageCapabilityClaim('vendor/notify', $digest, [
            'mutation' => 'none',
            'external_effect' => 'none',
            'kernel_only_eligible' => true,
        ]);
    }

    /**
     * A CLAIM DOES NOT CLASSIFY, NOT EVEN WHEN IT IS THE ONLY THING AVAILABLE.
     *
     * This is the case that decides everything: there is metadata in hand, it reads exactly like a
     * profile, and returning it would make everything downstream work without complaining. If this
     * test falls, the whole constitution rests on whatever each package cares to declare.
     */
    public function testAClaimDoesNotClassifyEvenWhenItIsAllThereIs(): void
    {
        $verdict = (new ClassificationResolver())->resolve('vendor/notify', 'sha256:AAA', $this->convenientClaim(), self::NOW);

        self::assertFalse($verdict['classified']);
        self::assertSame([], $verdict['profile'], 'what it declared does not enter the control derivation');
        self::assertSame('none', $verdict['source']);
        self::assertStringContainsString('GOV-11', $verdict['why']);
    }

    /** And its own type says so: a claim grants nothing. */
    public function testAClaimGrantsNothing(): void
    {
        self::assertSame([], $this->convenientClaim()->grants());
        self::assertSame('none', $this->convenientClaim()->claims('mutation'), 'what it asserts can be read…');
    }

    /** With a valid attestation over this digest, it governs and the claim still does not count. */
    public function testAValidAttestationOverThisDigestIsWhatGoverns(): void
    {
        $resolver = new ClassificationResolver([
            new ControlAttestation('vendor/notify', 'sha256:AAA', 'authority:classification', [
                'mutation' => 'persistent',
                'externality' => 'third_party',
            ], 'firma-valida'),
        ]);

        $verdict = $resolver->resolve('vendor/notify', 'sha256:AAA', $this->convenientClaim(), self::NOW);

        self::assertTrue($verdict['classified']);
        self::assertSame('third_party', $verdict['profile']['externality'], 'the attestation wins, not the claim');
        self::assertStringContainsString('authority:classification', $verdict['source']);
    }

    /**
     * A NEW VERSION INHERITS ABSOLUTELY NOTHING.
     *
     * `package@1.2.0 sha256:AAA` attested; `package@1.2.1 sha256:BBB` not. Binding to the version
     * instead of the digest would let a publisher ship something else under an already-classified
     * number — the cheapest way to break the entire chain.
     */
    public function testADifferentDigestInheritsNothingFromTheOne(): void
    {
        $resolver = new ClassificationResolver([
            new ControlAttestation('vendor/notify', 'sha256:AAA', 'authority:example', ['mutation' => 'none'], 'f'),
        ]);

        self::assertTrue($resolver->resolve('vendor/notify', 'sha256:AAA', null, self::NOW)['classified']);
        self::assertFalse($resolver->resolve('vendor/notify', 'sha256:BBB', null, self::NOW)['classified']);
    }

    /** The state says it too: when content changes you return to the start, not to the previous step. */
    public function testWhenContentChangesThePackageReturnsToTheStart(): void
    {
        self::assertSame(PackageState::Acquired, PackageState::Active->afterContentChanges());
        self::assertSame(PackageState::Acquired, PackageState::Attested->afterContentChanges());
    }

    /**
     * AN EXPIRED ATTESTATION DOES NOT DEGRADE TO A CLAIM: it leaves the artifact UNCLASSIFIED.
     *
     * This is the route by which the interested party's metadata would govern again without anybody
     * deciding anything — you would only have to wait for the expiry.
     */
    public function testAnExpiredAttestationLeavesItUnclassifiedRatherThanReturningTheClaim(): void
    {
        $resolver = new ClassificationResolver([
            new ControlAttestation('vendor/notify', 'sha256:AAA', 'authority:example', ['mutation' => 'none'], 'f', expiresAt: self::NOW - 1),
        ]);

        $verdict = $resolver->resolve('vendor/notify', 'sha256:AAA', $this->convenientClaim(), self::NOW);

        self::assertFalse($verdict['classified']);
        self::assertSame([], $verdict['profile']);
        self::assertStringContainsString('has expired', $verdict['why']);
    }

    /**
     * INSTALLING IS NOT ACTIVATING, AND NEITHER IS ATTESTING.
     *
     * `P19.4` is called «the agent installs», and that phrase blends acquiring, quarantining, reading
     * claims and granting capabilities. While they share a name, authorising one authorises all four.
     */
    public function testBeingAttestedIsNotBeingAllowedToAct(): void
    {
        $resolver = new ClassificationResolver([
            new ControlAttestation('vendor/notify', 'sha256:AAA', 'authority:example', ['mutation' => 'none'], 'f'),
        ]);

        self::assertFalse(
            $resolver->mayActivate(PackageState::Attested, 'vendor/notify', 'sha256:AAA', null, self::NOW),
            'attested means having the classification, not the permission',
        );
        self::assertTrue($resolver->mayActivate(PackageState::Activatable, 'vendor/notify', 'sha256:AAA', null, self::NOW));
    }

    /** And a quarantined package does not act even when classified: its code is here and does not run. */
    public function testAQuarantinedPackageDoesNotActEvenWhenClassified(): void
    {
        $resolver = new ClassificationResolver([
            new ControlAttestation('vendor/notify', 'sha256:AAA', 'authority:example', [], 'f'),
        ]);

        foreach ([PackageState::Quarantined, PackageState::ClaimsExtracted, PackageState::SignatureVerified] as $state) {
            self::assertFalse($resolver->mayActivate($state, 'vendor/notify', 'sha256:AAA', null, self::NOW));
        }
    }

    /** A claim without a digest is refused: it could not be attested later, because of what? */
    public function testAClaimWithoutADigestIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PackageCapabilityClaim('vendor/notify', '  ', ['mutation' => 'none']);
    }

    /** And an attestation without an issuer does not exist either: nobody to hold to account. */
    public function testAnAttestationWithoutAnIssuerIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ControlAttestation('vendor/notify', 'sha256:AAA', '', [], 'firma');
    }
}
