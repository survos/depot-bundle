<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Realtime\Event;

final readonly class OcrCompleted
{
    public function __construct(
        public string $assetId,
        public int $characterCount,
    ) {
    }
}
