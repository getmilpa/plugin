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
 * What a package says about itself. It is not a classification and it never becomes one.
 *
 * ── THE LEAK THIS TYPE EXISTS TO CLOSE ──────────────────────────────────────────────────────────
 *
 * `extra.milpa.capability` is written by the package. Today nothing stops it from declaring:
 *
 *     mutation: none · external_effect: none · kernel_only_eligible: true
 *
 * for an operation that sends email. And re-validating at the Tool Broker does NOT save you, because
 * the broker reads the same catalogue. The leak is not in the runtime: it is one layer below, in the
 * supply — and it lands squarely on the marketplace thesis, because a marketplace is by definition
 * untrusted third parties supplying catalogue entries.
 *
 *     GOV-11 — Control metadata declared by a third party constitutes a claim, never a
 *              classification.
 *
 * ── WHY A CLASS AND NOT AN ARRAY WITH A COMMENT ─────────────────────────────────────────────────
 *
 * Because an array gets passed to a function expecting a classification and nobody notices. The type
 * is what makes the confusion fail to compile: a signature asking for `ControlAttestation` cannot
 * receive this, and there is no conversion — only issuance by an authority, which is a different act.
 */
final class PackageCapabilityClaim
{
    /**
     * @param string               $package  the name the package gives itself
     * @param string               $digest   hash of the artifact it was read from — a claim without a
     *                                       digest cannot be attested later, because of what?
     * @param array<string, mixed> $declared what it says, verbatim, uninterpreted
     */
    public function __construct(
        public readonly string $package,
        public readonly string $digest,
        public readonly array $declared,
    ) {
        if (trim($digest) === '') {
            throw new \InvalidArgumentException(
                "claim from «{$package}» without a digest: a claim that does not say which artifact "
                . 'it came from cannot be attested, and would float over every future version'
            );
        }
    }

    /**
     * What this claim authorises: NOTHING.
     *
     * The method exists so the question has an answer written into the type, instead of living in
     * the head of whoever reads it.
     *
     * @return array<never, never>
     */
    public function grants(): array
    {
        return [];
    }

    /**
     * What the package asserts about a field — named for what it is.
     *
     * It is called `claims()` and not `get()` on purpose. `$claim->get('mutation')` reads as a fact;
     * `$claim->claims('mutation')` reads as an interested party's assertion, which is what it is.
     */
    public function claims(string $field): mixed
    {
        return $this->declared[$field] ?? null;
    }
}
