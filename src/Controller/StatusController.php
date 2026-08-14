<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Controller;

use Survos\DepotBundle\Service\DepotHealthService;
use Survos\DepotBundle\Service\ScanJobStatusStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Remote diagnosability for a headless, cloudflared-only appliance -- see
 * the GitHub issue this was built for: a real ScanJob sat at pairs_scanned=0
 * for several minutes with zero visibility into whether the consumer ever
 * picked up the message, the scanner was detected, or something failed
 * silently before ever calling ssai's fail(). Cheap and fast by default (no
 * live hardware probe on every ping -- scanner detection is cached and only
 * re-probed on request, per the issue's own suggestion).
 */
final class StatusController extends AbstractController
{
    public function __construct(
        private readonly ScanJobStatusStore $statusStore,
        private readonly DepotHealthService $health,
        private readonly CacheInterface $cache,
        #[Autowire(service: 'messenger.transport.scan_jobs')] private readonly MessageCountAwareInterface $scanJobsTransport,
        #[Autowire('%env(DEPOT_INBOUND_TOKEN)%')] private readonly string $expectedToken,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    #[Route('/internal/status', name: 'internal_status', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->expectedToken === '' || $request->headers->get('X-Internal-Token') !== $this->expectedToken) {
            return new JsonResponse(['ok' => false, 'reason' => 'unauthorized'], 401);
        }

        $forceProbe = $request->query->get('probe') === '1';
        $job = $this->statusStore->read();

        return new JsonResponse([
            'ok'               => true,
            'version'          => $this->gitCommit(),
            'depotWebUptimeS'  => $this->depotWebUptimeSeconds(),
            'scanner'          => $this->probeScanner($forceProbe),
            'scanJobsQueued'   => $this->scanJobsTransport->getMessageCount(),
            'scanWorkerActive' => $this->health->scanWorkerActive(),
            'currentJob'       => [
                'jobId'                => $job['jobId'] ?? null,
                'intakeCode'           => $job['intakeCode'] ?? null,
                'status'               => $job['status'] ?? null,
                'lastError'            => $job['lastError'] ?? null,
                'startedAt'            => $job['startedAt'] ?? null,
                'lastActivityAt'       => $job['lastActivityAt'] ?? null,
                'lastSuccessfulScanAt' => $job['lastSuccessfulScanAt'] ?? null,
            ],
        ]);
    }

    /**
     * @return array{detected: bool, probedAt: string}
     */
    private function probeScanner(bool $forceRefresh): array
    {
        $key = 'depot_status_scanner_probe';
        if ($forceRefresh) {
            $this->cache->delete($key);
        }

        return $this->cache->get($key, function (ItemInterface $item): array {
            $item->expiresAfter(300);

            return ['detected' => $this->health->scannerDetected(), 'probedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)];
        });
    }

    private function depotWebUptimeSeconds(): ?int
    {
        try {
            $process = new Process(['systemctl', '--user', 'show', 'depot-web.service', '--property=ActiveEnterTimestamp', '--value']);
            $process->run();
            $raw = trim($process->getOutput());
            if ($raw === '' || $raw === 'n/a') {
                return null;
            }

            return (new \DateTimeImmutable())->getTimestamp() - (new \DateTimeImmutable($raw))->getTimestamp();
        } catch (\Throwable) {
            return null;
        }
    }

    private function gitCommit(): ?string
    {
        try {
            $process = new Process(['git', 'rev-parse', '--short', 'HEAD'], $this->projectDir);
            $process->run();
            $commit = trim($process->getOutput());

            return $commit !== '' ? $commit : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
