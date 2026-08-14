<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Realtime;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Turns one raw Redis Pub/Sub message into a local RealtimeEventReceived
 * dispatch. Deliberately separate from SubscribeEventsCommand's blocking
 * \Redis::subscribe() loop so this decode/dispatch/error-handling logic is
 * unit-testable without a live Redis connection.
 */
final class RedisEventSubscriber
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handle(string $rawMessage): void
    {
        try {
            $envelope = json_decode($rawMessage, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('Realtime event: invalid JSON received', ['error' => $e->getMessage()]);

            return;
        }

        if (!\is_array($envelope) || !isset($envelope['type'], $envelope['id'], $envelope['source'], $envelope['occurredAt'])) {
            $this->logger->warning('Realtime event: envelope missing required fields', ['raw' => $rawMessage]);

            return;
        }

        $this->logger->info('Realtime event received', [
            'type' => $envelope['type'],
            'asset' => $envelope['asset'] ?? null,
            'source' => $envelope['source'],
            'event' => $envelope['id'],
        ]);

        $this->dispatcher->dispatch(new RealtimeEventReceived(
            id: (string) $envelope['id'],
            type: (string) $envelope['type'],
            source: (string) $envelope['source'],
            occurredAt: (string) $envelope['occurredAt'],
            data: \is_array($envelope['data'] ?? null) ? $envelope['data'] : [],
            asset: isset($envelope['asset']) ? (string) $envelope['asset'] : null,
        ));
    }
}
