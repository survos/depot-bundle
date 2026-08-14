<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Realtime;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * Fire-and-forget: publishing is best-effort. A Redis failure must never
 * fail the caller — the processing result was already persisted through the
 * authoritative HTTP/API path before this is reached.
 *
 * #[Exclude]: needs a live \Redis connection, which nothing autowires --
 * EventPublisherFactory constructs it directly with `new`.
 */
#[Exclude]
final class RedisEventPublisher implements EventPublisherInterface
{
    public function __construct(
        private readonly \Redis|\RedisCluster $redis,
        private readonly string $channel,
        private readonly EventSerializer $serializer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function publish(object $event): void
    {
        $envelope = $this->serializer->serialize($event);

        try {
            $this->redis->publish($this->channel, $envelope->toJson());

            $this->logger->info('Realtime event published', [
                'type' => $envelope->type,
                'asset' => $envelope->asset,
                'source' => $envelope->source,
                'event' => $envelope->id,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Realtime event publish failed', [
                'type' => $envelope->type,
                'asset' => $envelope->asset,
                'source' => $envelope->source,
                'event' => $envelope->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
