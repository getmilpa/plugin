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
 * Where a package is in its life — and where «installed» stops being one word.
 *
 * ── WHY THIS IS NOT A DECORATIVE ENUM ───────────────────────────────────────────────────────────
 *
 * `P19.4` is called «the agent installs», and that phrase blends four actions whose risks are
 * nothing alike: acquiring an artifact, quarantining it, reading what it says about itself, and
 * letting its operations hold real capabilities. An agent can hold authority for the first two
 * without holding it for the last — and while all four are called «installing», granting one grants
 * all four.
 *
 *     Installing is not activating. Activating is not granting authority.
 *
 * A package can exist on disk while being authorised to do nothing at all. That is not an awkward
 * intermediate state: it is the normal state of everything that arrives from outside.
 */
enum PackageState: string
{
    /** Known to exist. Nothing was downloaded. */
    case Discovered = 'discovered';

    /** The artifact is on disk. Nobody has looked at it. */
    case Acquired = 'acquired';

    /** Its provenance signature verifies — WHO published it is known, not that it is safe. */
    case SignatureVerified = 'signature_verified';

    /** Isolated. Its code is here and it does not run. */
    case Quarantined = 'quarantined';

    /** What it declares about itself has been read. It is still a claim. */
    case ClaimsExtracted = 'claims_extracted';

    /** An independent authority has yet to classify its effects. */
    case ClassificationPending = 'classification_pending';

    /** A signed attestation exists, bound to this exact digest. */
    case Attested = 'attested';

    /** It could be activated. It has not been. */
    case Activatable = 'activatable';

    /** Its operations hold real capabilities. */
    case Active = 'active';

    /**
     * Whether a package in this state may hold capabilities.
     *
     * Only two states allow it, and `Attested` is not one of them: being attested means having the
     * classification, not having the permission. The separation exists because these are two
     * decisions and different people make them.
     */
    public function mayHoldCapabilities(): bool
    {
        return $this === self::Activatable || $this === self::Active;
    }

    /**
     * The state an artifact returns to when its content changes.
     *
     * NOT the previous one in the list: the beginning. A new version inherits absolutely nothing
     * from the old one — not the signature, not the classification, not the activation — because what
     * was attested was a digest, and that digest no longer exists.
     */
    public function afterContentChanges(): self
    {
        return self::Acquired;
    }
}
