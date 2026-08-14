<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Tests\Realtime;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Survos\DepotBundle\Realtime\RealtimeEventReceived;
use Survos\DepotBundle\Realtime\RedisEventSubscriber;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class RedisEventSubscriberTest extends TestCase
{
    public function testHandleDispatchesARealtimeEventReceivedFromAValidEnvelope(): void
    {
        $dispatcher = new EventDispatcher();
        $received = null;
        $dispatcher->addListener(RealtimeEventReceived::class, function (RealtimeEventReceived $event) use (&$received): void {
            $received = $event;
        });

        $subscriber = new RedisEventSubscriber($dispatcher, new NullLogger());
        $subscriber->handle(json_encode([
            'id' => '01K2...',
            'type' => 'depot.heartbeat',
            'source' => 'depot-rapp',
            'occurredAt' => '2026-08-14T10:03:42+00:00',
            'data' => ['label' => 'depot-rapp'],
        ], JSON_THROW_ON_ERROR));

        self::assertInstanceOf(RealtimeEventReceived::class, $received);
        self::assertSame('depot.heartbeat', $received->type);
        self::assertSame('depot-rapp', $received->source);
        self::assertSame(['label' => 'depot-rapp'], $received->data);
        self::assertNull($received->asset);
    }

    public function testHandlePromotesAssetFromTheEnvelope(): void
    {
        $dispatcher = new EventDispatcher();
        $received = null;
        $dispatcher->addListener(RealtimeEventReceived::class, function (RealtimeEventReceived $event) use (&$received): void {
            $received = $event;
        });

        $subscriber = new RedisEventSubscriber($dispatcher, new NullLogger());
        $subscriber->handle(json_encode([
            'id' => '01K2...',
            'type' => 'asset.ocr.completed',
            'source' => 'server',
            'occurredAt' => '2026-08-14T10:03:42+00:00',
            'data' => ['characterCount' => 1437],
            'asset' => '019...',
        ], JSON_THROW_ON_ERROR));

        self::assertSame('019...', $received->asset);
    }

    public function testHandleSilentlyIgnoresInvalidJson(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatched = false;
        $dispatcher->addListener(RealtimeEventReceived::class, function () use (&$dispatched): void {
            $dispatched = true;
        });

        (new RedisEventSubscriber($dispatcher, new NullLogger()))->handle('not json');

        self::assertFalse($dispatched);
    }

    public function testHandleSilentlyIgnoresAnEnvelopeMissingRequiredFields(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatched = false;
        $dispatcher->addListener(RealtimeEventReceived::class, function () use (&$dispatched): void {
            $dispatched = true;
        });

        (new RedisEventSubscriber($dispatcher, new NullLogger()))->handle(json_encode(['type' => 'depot.heartbeat'], JSON_THROW_ON_ERROR));

        self::assertFalse($dispatched);
    }
}
