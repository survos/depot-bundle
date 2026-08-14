<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Realtime\Event;

final readonly class DepotHeartbeat
{
    /**
     * @param list<string> $tenants
     * @param list<string> $capabilities
     */
    public function __construct(
        public string $label,
        public string $url,
        public array $tenants,
        public array $capabilities,
        public ?string $imgproxyUrl = null,
    ) {
    }
}
