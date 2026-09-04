<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Message;

/**
 * Re-probe attached hardware now, rather than waiting for the 2-minute schedule.
 *
 * scanimage -L's full backend enumeration measures ~20s, which is why it is not on the
 * 15s heartbeat path -- but that cadence is the wrong shape for an operator standing at
 * the scanner. They plug it in, clear a jam, or power it on, and then watch a disabled
 * Start Scanning button for up to two minutes with nothing to tell them whether it
 * worked. Asking on demand costs the same 20s, once, when someone is actually waiting.
 *
 * Async on purpose: the request that triggers it returns immediately and the result
 * arrives on the next heartbeat, so a slow probe never holds up a page load.
 */
final class RescanDevicesMessage
{
    public function __construct(
        public readonly string $reason = 'requested',
    ) {
    }
}
