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

namespace Milpa\Plugin\Supply;

/**
 * Where an authoritative classification comes from — and where it does NOT, which is the half that
 * matters.
 *
 * ── WHAT THIS RESOLVER REFUSES TO DO ────────────────────────────────────────────────────────────
 *
 * Turn a claim into a classification. There is no route: not behind a flag, not «provisionally», not
 * «while the attestation is on its way». A claim and an attestation are different types precisely so
 * that the conversion does not exist as a representable move — which is GOV-02: an invariant is
 * governed by removing the transition that would violate it, not by writing that it must not happen.
 *
 * ── THE PRECEDENCE RULE, AND WHY IT IS NOT A PREFERENCE ─────────────────────────────────────────
 *
 * When a valid attestation covers this digest, it governs. When there is none, the result is NOT
 * what the package said about itself: it is `unclassified`, which under GOV-05 weighs as the worst
 * case in every dimension. The claim is kept and returned — useful for knowing what somebody
 * promised — but it does not enter the control derivation.
 *
 * The difference only shows in the case that matters: a package declaring `mutation: none` for an
 * operation that sends email. With precedence, that claim wins whenever no attestation exists and
 * the broker consumes it without suspicion. Without precedence, an unattested package is treated as
 * what it is — something we know nothing about — and the convenient declaration buys it nothing.
 *
 * ── AND THE LIMIT THIS RESOLVER CANNOT COVER ────────────────────────────────────────────────────
 *
 * Even with a perfect attestation, a PHP package in the same process can open a socket or run a
 * command without going through any declared operation. Measured 2026-08-05 across the real family:
 * **13 of 34 packages can, and they are ours.** While that holds, a classification describes what an
 * operation SAYS it does, not what its package CAN do (GOV-12). This resolver closes the metadata
 * chain; it does not close the process one.
 */
final class ClassificationResolver
{
    /** @var list<ControlAttestation> */
    private array $attestations = [];

    /** @param list<ControlAttestation> $attestations */
    public function __construct(array $attestations = [])
    {
        foreach ($attestations as $attestation) {
            $this->attestations[] = $attestation;
        }
    }

    /**
     * The classification governing this artifact — or the absence of one, said with its reason.
     *
     * The reason travels because «never classified» and «its attestation expired» ask different
     * things of whoever reads them, and a boolean would make them indistinguishable.
     *
     * @return array{classified: bool, profile: array<string, mixed>, source: string, why: string}
     */
    public function resolve(string $package, string $digest, ?PackageCapabilityClaim $claim, int $moment): array
    {
        foreach ($this->attestations as $attestation) {
            if (!$attestation->covers($package, $digest)) {
                continue;
            }
            if (!$attestation->isValidAt($moment)) {
                return [
                    'classified' => false,
                    'profile' => [],
                    'source' => 'none',
                    'why' => "the attestation for «{$package}» over this digest has expired — an expired "
                        . 'classification does not degrade to a claim, it leaves the artifact unclassified',
                ];
            }

            return [
                'classified' => true,
                'profile' => $attestation->profile,
                'source' => 'attestation:' . $attestation->issuer,
                'why' => 'attested by an authority and bound to this exact digest',
            ];
        }

        // HERE IS THE DOOR THAT DOES NOT OPEN.
        //
        // There is a claim in hand, with fields that read exactly like a profile, and returning it
        // would make everything downstream work without complaining. That is precisely what is not
        // done here.
        return [
            'classified' => false,
            'profile' => [],
            'source' => 'none',
            'why' => $claim === null
                ? "«{$package}» declares nothing and nobody has attested it"
                : "«{$package}» declares its own classification and NOBODY has attested it: what a "
                    . 'package says about itself is a claim (GOV-11), and a claim does not classify '
                    . 'even when it is the only thing available',
        ];
    }

    /**
     * Whether this artifact may receive capabilities.
     *
     * Being classified is not enough and being attested is not either: the package state must allow
     * it too. These are two decisions — what is this, and do we let it act — and different people
     * make them. Collapsing them would make classifying the same as granting.
     */
    public function mayActivate(PackageState $state, string $package, string $digest, ?PackageCapabilityClaim $claim, int $moment): bool
    {
        return $state->mayHoldCapabilities()
            && $this->resolve($package, $digest, $claim, $moment)['classified'];
    }
}
