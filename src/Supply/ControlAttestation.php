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
 * Who takes responsibility for a classification, over which exact artifact, and until when.
 *
 * ── THREE SIGNATURES THAT ARE NOT THE SAME SIGNATURE ────────────────────────────────────────────
 *
 * A marketplace asks three different questions, and conflating them is the vulnerability:
 *
 *     publisher signature   who published this?            → provenance
 *     control attestation   who answers for its class?     → classification
 *     broker mediation      what can it do while running?  → enforcement
 *
 * **A malicious package can be perfectly signed by its author.** Provenance proves who, not what
 * (GOV-17).
 *
 * ── WHY IT BINDS TO THE DIGEST AND NOT TO THE VERSION ───────────────────────────────────────────
 *
 * `package@1.2.0 sha256:AAA` has an attestation. `package@1.2.1 sha256:BBB` **inherits nothing** —
 * not partially, not provisionally. Binding to the version would let a publisher ship something else
 * under a number that was already classified, the cheapest way to break the whole chain.
 */
final class ControlAttestation
{
    /**
     * @param string               $package   which package it refers to
     * @param string               $digest    the EXACT hash of the classified artifact
     * @param string               $issuer    who takes responsibility — an authority, not the package
     * @param array<string, mixed> $profile   the effect classification being attested
     * @param string               $signature the issuer's signature over the above
     * @param int|null             $expiresAt when it stops holding, or `null` if it never expires
     */
    public function __construct(
        public readonly string $package,
        public readonly string $digest,
        public readonly string $issuer,
        public readonly array $profile,
        public readonly string $signature,
        public readonly ?int $expiresAt = null,
    ) {
        foreach (['package' => $package, 'digest' => $digest, 'issuer' => $issuer, 'signature' => $signature] as $field => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException("attestation without {$field}: it can be neither verified nor held to account");
            }
        }
    }

    /**
     * Whether this attestation applies to the artifact actually in hand.
     *
     * It compares the digest, not the name or the version. A different artifact with the same name
     * is not attested: it is unattested, which under GOV-05 is the worst case and not the best.
     */
    public function covers(string $package, string $digest): bool
    {
        return $this->package === $package && hash_equals($this->digest, $digest);
    }

    /**
     * Whether it still holds at the given moment.
     *
     * The moment is passed in, not read from the clock: an attestation judged against `time()` gives
     * a different verdict on every run and cannot be reproduced, which is the opposite of what an
     * attestation exists to make possible.
     */
    public function isValidAt(int $moment): bool
    {
        return $this->expiresAt === null || $moment < $this->expiresAt;
    }
}
