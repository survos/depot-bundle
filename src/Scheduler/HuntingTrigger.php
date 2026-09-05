<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Scheduler;

use Symfony\Component\Scheduler\Trigger\TriggerInterface;

/**
 * Fires often while something is missing, and backs off once it is found.
 *
 * Device probing has an asymmetry a fixed interval cannot express: once the
 * scanner is present, re-confirming it every couple of minutes is plenty, but
 * while it is absent every extra second of interval is time an operator spends
 * standing at a station that does not yet know its hardware arrived. The common
 * case is exactly that -- the station boots before the scanner is plugged in,
 * because it always used to be plugged in *during* the boot.
 *
 * The hunting interval is deliberately longer than the ~20s a full `scanimage -L`
 * enumeration takes, so runs cannot pile up on each other.
 */
final class HuntingTrigger implements TriggerInterface
{
    /**
     * @param \Closure(): bool $found returns true once the thing being hunted for is present
     */
    public function __construct(
        private readonly \Closure $found,
        private readonly int $huntingSeconds = 30,
        private readonly int $settledSeconds = 120,
    ) {
    }

    public function getNextRunDate(\DateTimeImmutable $run): ?\DateTimeImmutable
    {
        // Defensive: a probe schedule that throws would stop the scheduler
        // outright, taking the heartbeat down with it. Hunting is the safe
        // default -- worst case it probes more often than strictly needed.
        try {
            $found = ($this->found)();
        } catch (\Throwable) {
            $found = false;
        }

        return $run->modify(sprintf('+%d seconds', $found ? $this->settledSeconds : $this->huntingSeconds));
    }

    public function __toString(): string
    {
        return sprintf('every %ds while hunting, %ds once found', $this->huntingSeconds, $this->settledSeconds);
    }
}
