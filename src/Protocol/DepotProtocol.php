<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Protocol;

/**
 * The contract between depot and the ai-tools process sitting beside it.
 *
 * It lives in this bundle because this bundle is the one thing both ends of that
 * contract can rely on being installed. ai-tools used to obtain its constants by
 * shell-invoking ssai's app:dump-constants -- which coupled an edge appliance to a
 * checkout it has no business carrying, and failed in the worst possible way when
 * that checkout was present but had no vendor/: the command fatally errored, the
 * warning scrolled past, ai-tools started, answered 200 and reported itself
 * reachable, while unable to find a single file to crop.
 *
 * Depot is always co-located with ai-tools. ssai is not, and on an appliance it
 * must not be.
 *
 * VERSION is the compatibility signal. Bump it when the meaning of anything
 * emitted alongside it changes, so a stale constants_local.py is detectable
 * instead of merely wrong.
 */
final class DepotProtocol
{
    public const VERSION = 1;

    /**
     * Local paths are NOT part of this contract.
     *
     * A storage root is a property of the machine -- an external drive on one
     * station, an internal disk on another -- so it is declared in the
     * environment and merely reported here, never invented. Dumping a path as
     * though it were a shared constant is how three machines ended up with four
     * different storage locations.
     */
    public const SHARED_DIR_ENV = 'AI_TOOLS_SHARED_DIR';
}
