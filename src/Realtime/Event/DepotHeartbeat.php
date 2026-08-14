<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Realtime\Event;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
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
