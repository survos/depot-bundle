<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Realtime;

/**
 * Announces that something happened. Never authoritative — the API/database
 * remains the source of truth. A failed or missed publish must never be
 * treated as a processing failure.
 */
interface EventPublisherInterface
{
    public function publish(object $event): void;
}
