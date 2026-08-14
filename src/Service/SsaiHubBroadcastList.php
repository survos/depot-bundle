<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Resolves the full list of ssai hubs this depot broadcasts to: the primary
 * SSAI_HUB_URL (which also owns this depot's ScanJob lifecycle -- see
 * SsaiScanJobHubService, unchanged) plus any extra SSAI_HUB_BROADCAST_URLS.
 * Shared by DepotSyncService::heartbeat() (presence) and SsaiScanHubService::ingestPair()
 * (scan results) so the two can't drift apart on which hubs are "known".
 */
final class SsaiHubBroadcastList
{
    public function __construct(
        #[Autowire('%env(default::SSAI_HUB_URL)%')] private readonly ?string $primaryUrl,
        #[Autowire('%env(default::SSAI_HUB_BROADCAST_URLS)%')] private readonly ?string $extraUrls,
    ) {
    }

    /** @return list<string> deduped, primary first */
    public function all(): array
    {
        $urls = [trim((string) $this->primaryUrl)];

        foreach (explode(',', (string) $this->extraUrls) as $url) {
            $urls[] = trim($url);
        }

        $urls = array_values(array_unique(array_filter($urls, static fn(string $u): bool => $u !== '')));

        return array_map(static fn(string $u): string => rtrim($u, '/'), $urls);
    }

    public function primary(): ?string
    {
        $url = trim((string) $this->primaryUrl);

        return $url !== '' ? rtrim($url, '/') : null;
    }
}
