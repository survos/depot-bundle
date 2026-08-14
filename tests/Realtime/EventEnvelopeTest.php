<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Tests\Realtime;

use PHPUnit\Framework\TestCase;
use Survos\DepotBundle\Realtime\EventEnvelope;

final class EventEnvelopeTest extends TestCase
{
    public function testToArrayIncludesRequiredMetadata(): void
    {
        $envelope = new EventEnvelope(
            id: '01K2...',
            type: 'asset.ocr.completed',
            source: 'server',
            occurredAt: '2026-08-14T10:03:42+00:00',
            data: ['characterCount' => 1437],
        );

        self::assertSame([
            'id' => '01K2...',
            'type' => 'asset.ocr.completed',
            'source' => 'server',
            'occurredAt' => '2026-08-14T10:03:42+00:00',
            'data' => ['characterCount' => 1437],
        ], $envelope->toArray());
    }

    public function testToArrayOmitsAssetWhenNotSet(): void
    {
        $envelope = new EventEnvelope(
            id: '01K2...',
            type: 'depot.heartbeat',
            source: 'depot-rapp',
            occurredAt: '2026-08-14T10:03:42+00:00',
        );

        self::assertArrayNotHasKey('asset', $envelope->toArray());
    }

    public function testToArrayPromotesAssetToTopLevelWhenSet(): void
    {
        $envelope = new EventEnvelope(
            id: '01K2...',
            type: 'asset.ocr.completed',
            source: 'server',
            occurredAt: '2026-08-14T10:03:42+00:00',
            data: ['characterCount' => 1437],
            asset: '019...',
        );

        self::assertSame('019...', $envelope->toArray()['asset']);
    }

    public function testToJsonProducesValidJson(): void
    {
        $envelope = new EventEnvelope(
            id: '01K2...',
            type: 'asset.ocr.completed',
            source: 'server',
            occurredAt: '2026-08-14T10:03:42+00:00',
            data: ['characterCount' => 1437],
            asset: '019...',
        );

        self::assertSame($envelope->toArray(), json_decode($envelope->toJson(), true, flags: JSON_THROW_ON_ERROR));
    }
}
