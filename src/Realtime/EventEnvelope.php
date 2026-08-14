<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Realtime;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class EventEnvelope
{
    public function __construct(
        public string $id,
        public string $type,
        public string $source,
        public string $occurredAt,
        public array $data = [],
        public ?string $asset = null,
    ) {
    }

    public function toArray(): array
    {
        $envelope = [
            'id' => $this->id,
            'type' => $this->type,
            'source' => $this->source,
            'occurredAt' => $this->occurredAt,
            'data' => $this->data,
        ];

        if (null !== $this->asset) {
            $envelope['asset'] = $this->asset;
        }

        return $envelope;
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }
}
