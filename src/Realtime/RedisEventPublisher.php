<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Realtime;

use Psr\Cache\CacheItemPoolInterface;
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
    /** Cache key DepotHealthService::redisStatus() reads back -- keep the two in sync if this ever changes. */
    public const PULSE_CACHE_KEY = 'depot_events.last_pulse';

    public function __construct(
        private readonly \Redis|\RedisCluster $redis,
        private readonly string $channel,
        private readonly EventSerializer $serializer,
        private readonly LoggerInterface $logger,
        private readonly ?CacheItemPoolInterface $pulse = null,
    ) {
    }

    public function publish(object $event): void
    {
        $envelope = $this->serializer->serialize($event);

        try {
            $this->redis->publish($this->channel, $envelope->toJson());
            $this->recordPulse($envelope->type, true, null);

            $this->logger->info('Realtime event published', [
                'type' => $envelope->type,
                'asset' => $envelope->asset,
                'source' => $envelope->source,
                'event' => $envelope->id,
            ]);
        } catch (\Throwable $e) {
            $this->recordPulse($envelope->type, false, $e->getMessage());

            $this->logger->warning('Realtime event publish failed', [
                'type' => $envelope->type,
                'asset' => $envelope->asset,
                'source' => $envelope->source,
                'event' => $envelope->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Feeds DepotHealthService::redisStatus() -- the whole point is turning
     * "is Redis a black box" into "here's the last thing we actually tried
     * to send and whether it worked", visible on the home page without
     * digging through logs. Never lets a cache hiccup take down publishing
     * itself, same fire-and-forget contract as the publish above.
     */
    private function recordPulse(string $type, bool $success, ?string $error): void
    {
        if ($this->pulse === null) {
            return;
        }

        try {
            $item = $this->pulse->getItem(self::PULSE_CACHE_KEY);
            $item->set([
                'at' => (new \DateTimeImmutable())->format(DATE_ATOM),
                'channel' => $this->channel,
                'type' => $type,
                'success' => $success,
                'error' => $error,
            ]);
            $this->pulse->save($item);
        } catch (\Throwable) {
        }
    }
}
