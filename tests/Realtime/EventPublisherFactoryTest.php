<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Tests\Realtime;

use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Survos\DepotBundle\Realtime\EventNameResolver;
use Survos\DepotBundle\Realtime\EventPublisherFactory;
use Survos\DepotBundle\Realtime\EventSerializer;
use Survos\DepotBundle\Realtime\NullEventPublisher;
use Survos\DepotBundle\Realtime\RedisEventPublisher;

final class EventPublisherFactoryTest extends TestCase
{
    public function testFallsBackToNullPublisherWhenDsnIsEmpty(): void
    {
        $publisher = EventPublisherFactory::create(
            enabled: true,
            dsn: '',
            channel: 'depot.events',
            nodeId: 'server',
            serializer: $this->serializer(),
            logger: new NullLogger(),
        );

        self::assertInstanceOf(NullEventPublisher::class, $publisher);
    }

    public function testFallsBackToNullPublisherWhenDisabledEvenWithADsn(): void
    {
        $publisher = EventPublisherFactory::create(
            enabled: false,
            dsn: 'redis://127.0.0.1:6379',
            channel: 'depot.events',
            nodeId: 'server',
            serializer: $this->serializer(),
            logger: new NullLogger(),
        );

        self::assertInstanceOf(NullEventPublisher::class, $publisher);
    }

    public function testBuildsARedisPublisherWhenEnabledWithADsnAndNodeId(): void
    {
        $publisher = EventPublisherFactory::create(
            enabled: true,
            dsn: 'redis://127.0.0.1:6379',
            channel: 'depot.events',
            nodeId: 'server',
            serializer: $this->serializer(),
            logger: new NullLogger(),
        );

        self::assertInstanceOf(RedisEventPublisher::class, $publisher);
    }

    public function testThrowsWhenEnabledWithADsnButNoNodeId(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/node_id/');

        EventPublisherFactory::create(
            enabled: true,
            dsn: 'redis://127.0.0.1:6379',
            channel: 'depot.events',
            nodeId: '',
            serializer: $this->serializer(),
            logger: new NullLogger(),
        );
    }

    private function serializer(): EventSerializer
    {
        return new EventSerializer(new EventNameResolver(), nodeId: 'server');
    }
}
