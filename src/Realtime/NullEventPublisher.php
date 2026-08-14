<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Realtime;

final class NullEventPublisher implements EventPublisherInterface
{
    public function publish(object $event): void
    {
    }
}
