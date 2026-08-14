<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * This station's own label -- DEPOT_LABEL if set, else `hostname` lowercased.
 * Shared by DepotSyncService::heartbeat() (presence) and SsaiScanHubService
 * (tags each ingestPair() call with who sent it) so a hub can resolve
 * "which depot actually has this file" instead of falling back to
 * Tenant::$edgeUrl's stale naming convention.
 */
final class DepotIdentity
{
    public function __construct(
        #[Autowire('%env(default::DEPOT_LABEL)%')] private readonly ?string $configuredLabel,
    ) {
    }

    public function label(): string
    {
        $label = trim((string) $this->configuredLabel);

        return $label !== '' ? $label : strtolower(trim((string) gethostname()));
    }
}
