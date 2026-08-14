<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Tests\Realtime;

use PHPUnit\Framework\TestCase;
use Survos\DepotBundle\Realtime\Event\OcrCompleted;
use Survos\DepotBundle\Realtime\EventNameResolver;
use Survos\DepotBundle\Realtime\EventSerializer;

final class EventSerializerTest extends TestCase
{
    public function testSerializePromotesAssetIdToTopLevelAssetAndDropsItFromData(): void
    {
        $serializer = new EventSerializer(
            new EventNameResolver([OcrCompleted::class => 'asset.ocr.completed']),
            nodeId: 'server',
        );

        $envelope = $serializer->serialize(new OcrCompleted('019...', 1437));

        self::assertSame('asset.ocr.completed', $envelope->type);
        self::assertSame('server', $envelope->source);
        self::assertSame('019...', $envelope->asset);
        self::assertSame(['characterCount' => 1437], $envelope->data);
        self::assertArrayNotHasKey('assetId', $envelope->data);
    }

    public function testSerializeAssignsAFreshIdAndTimestampPerCall(): void
    {
        $serializer = new EventSerializer(
            new EventNameResolver([OcrCompleted::class => 'asset.ocr.completed']),
            nodeId: 'server',
        );

        $first = $serializer->serialize(new OcrCompleted('019...', 1437));
        $second = $serializer->serialize(new OcrCompleted('019...', 1437));

        self::assertNotSame($first->id, $second->id);
    }
}
