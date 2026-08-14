<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Tests\Realtime;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Survos\DepotBundle\Realtime\Event\OcrCompleted;
use Survos\DepotBundle\Realtime\EventNameResolver;
use Survos\DepotBundle\Realtime\EventSerializer;
use Survos\DepotBundle\Realtime\RedisEventPublisher;

final class RedisEventPublisherTest extends TestCase
{
    public function testPublishSendsTheEnvelopeJsonOnTheConfiguredChannel(): void
    {
        $redis = new RecordingRedis();
        $publisher = new RedisEventPublisher(
            $redis,
            'depot.events',
            new EventSerializer(
                new EventNameResolver([OcrCompleted::class => 'asset.ocr.completed']),
                nodeId: 'server',
            ),
            new NullLogger(),
        );

        $publisher->publish(new OcrCompleted('019...', 1437));

        self::assertCount(1, $redis->published);
        [$channel, $payload] = $redis->published[0];
        self::assertSame('depot.events', $channel);

        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('asset.ocr.completed', $decoded['type']);
        self::assertSame('019...', $decoded['asset']);
        self::assertSame(['characterCount' => 1437], $decoded['data']);
    }

    public function testPublishNeverThrowsWhenRedisIsUnavailable(): void
    {
        $redis = new ThrowingRedis();
        $publisher = new RedisEventPublisher(
            $redis,
            'depot.events',
            new EventSerializer(
                new EventNameResolver([OcrCompleted::class => 'asset.ocr.completed']),
                nodeId: 'server',
            ),
            new NullLogger(),
        );

        $publisher->publish(new OcrCompleted('019...', 1437));

        self::assertTrue(true, 'publish() must swallow the Redis failure, not throw');
    }
}

final class RecordingRedis extends \Redis
{
    /** @var list<array{0: string, 1: string}> */
    public array $published = [];

    public function publish($channel, $message): int|false
    {
        $this->published[] = [$channel, $message];

        return 1;
    }
}

final class ThrowingRedis extends \Redis
{
    public function publish($channel, $message): int|false
    {
        throw new \RedisException('connection refused');
    }
}
