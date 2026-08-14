<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Realtime;

/**
 * Maps event DTO classes to stable, dotted wire names (e.g.
 * `asset.face-detection.completed`). Deliberately explicit rather than
 * derived from the class name — the wire contract must not depend on PHP
 * implementation details.
 */
final class EventNameResolver
{
    /**
     * @param array<class-string, string> $map the real catalog is bound
     *   explicitly in SurvosDepotBundle::registerRealtimeEvents() -- the
     *   default here only matters for unit tests constructing this
     *   directly with fixtures.
     */
    public function __construct(
        private readonly array $map = [],
    ) {
    }

    public function resolve(object $event): string
    {
        return $this->map[$event::class]
            ?? throw new \InvalidArgumentException(sprintf(
                'No event name registered for "%s". Register it in SurvosDepotBundle\'s event catalog.',
                $event::class,
            ));
    }
}
