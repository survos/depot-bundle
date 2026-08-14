<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Realtime;

use Symfony\Component\Uid\Uuid;

final class EventSerializer
{
    public function __construct(
        private readonly EventNameResolver $nameResolver,
        private readonly string $nodeId,
    ) {
    }

    public function serialize(object $event): EventEnvelope
    {
        $data = get_object_vars($event);

        $asset = null;
        if (isset($data['assetId'])) {
            $asset = (string) $data['assetId'];
            unset($data['assetId']);
        }

        return new EventEnvelope(
            id: Uuid::v7()->toRfc4122(),
            type: $this->nameResolver->resolve($event),
            source: $this->nodeId,
            occurredAt: (new \DateTimeImmutable())->format(DATE_ATOM),
            data: $data,
            asset: $asset,
        );
    }
}
