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

/**
 * Writes what changes the house's boot only if the house boots with it — the one place that rule lives.
 *
 * ── WHY THE PLUGIN OPERATIONS ASK IT (greenhouse decisions/0515) ────────────────────────────────
 *
 * `plugins.register` writes `config/plugins.php` and the toggles write the registry the boot reads. A
 * class that misses an interface method, registered or switched on, is a compile fatal in EVERY process
 * that boots the house — including the one that would turn it off again. {@see ActivationSafetyInterface}
 * judges the capability graph from metadata; it cannot see a fatal, because seeing one means booting.
 * So a write that changes what the kernel boots from is first applied to a copy of the house and that
 * copy is booted; the live file is written only if it booted, and the house is asked again after.
 *
 * ── WHY IT IS A CONTRACT, AND WHY IT TAKES THE WRITE ────────────────────────────────────────────
 *
 * Booting the house means knowing how the host boots — its entry files, its overlays, its PHP binary.
 * The host knows that; this package does not. And the rule — refuse, let recovery through, put the old
 * bytes back — is one rule for every writer of the house, so it lives with the host's witness and not
 * restated here: two places deciding what is safe to write is one too many. This package only says
 * WHAT it would write. A host that wires no witness keeps the old behaviour: judged by the graph alone,
 * and nothing claims it booted.
 */
interface BootWitnessInterface
{
    /**
     * Run `$commit` only if the house boots with `$writes` written and `$deletes` removed.
     *
     * `refused` is null when the write happened; otherwise it is the sentence to show, and nothing is left
     * written. `said` travels into the operation's answer: `house_boots` after a write, `unwritten` or
     * `rolled_back` (the paths) with a refusal, `still_broken` after a recovery that did not finish. With
     * `$recovery`, a house that does not boot as it is never refuses the write — the way back is not
     * refused because the house is already broken (decisions/0506).
     *
     * @param array<string, string> $writes  path relative to the app root → the bytes it will hold
     * @param callable(): void      $commit  the write itself, on the live house
     * @param list<string>          $deletes paths relative to the app root the write removes
     *
     * @return array{refused: ?string, said: array<string, mixed>}
     */
    public function writeIfItBoots(array $writes, callable $commit, bool $recovery = false, array $deletes = []): array;
}
