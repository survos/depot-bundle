<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SsaiScanJobHubService
{
    public function __construct(
        private readonly HttpClientInterface $ssaiHub,
    ) {
    }

    /** @return array{id: int, tenantId: string, intakeCode: string, status: string, startingLabel: ?string}|null */
    public function claimOne(string $tenantId, string $depotId): ?array
    {
        $response = $this->ssaiHub->request('POST', '/internal/scan-jobs/claim', [
            'json' => ['tenantId' => $tenantId, 'depotId' => $depotId],
        ]);

        $data = $response->toArray();

        return $data['job'] ?? null;
    }

    public function status(int $jobId): string
    {
        $response = $this->ssaiHub->request('GET', "/internal/scan-jobs/{$jobId}/status");

        return (string) ($response->toArray()['status'] ?? 'unknown');
    }

    public function heartbeat(int $jobId, int $pairsThisBatch): void
    {
        $this->ssaiHub->request('POST', "/internal/scan-jobs/{$jobId}/heartbeat", [
            'json' => ['pairsThisBatch' => $pairsThisBatch],
        ]);
    }

    public function markStopped(int $jobId): void
    {
        $this->ssaiHub->request('POST', "/internal/scan-jobs/{$jobId}/stopped");
    }

    public function fail(int $jobId, string $error): void
    {
        $this->ssaiHub->request('POST', "/internal/scan-jobs/{$jobId}/fail", [
            'json' => ['error' => $error],
        ]);
    }
}
