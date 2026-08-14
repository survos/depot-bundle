<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Realtime\Event;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class OcrCompleted
{
    public function __construct(
        public string $assetId,
        public int $characterCount,
    ) {
    }
}
