<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Realtime;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Dispatched locally (Symfony EventDispatcher, within one process) for every
 * valid envelope RedisEventSubscriber receives off the depot.events channel.
 * App-level listeners react to specific $type values -- this class itself
 * carries no domain knowledge of what any given type means.
 */
#[Exclude]
final readonly class RealtimeEventReceived
{
    public function __construct(
        public string $id,
        public string $type,
        public string $source,
        public string $occurredAt,
        public array $data,
        public ?string $asset = null,
    ) {
    }
}
