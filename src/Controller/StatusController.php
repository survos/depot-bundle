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

/**
 * Remote diagnosability for a headless, cloudflared-only appliance -- see
 * the GitHub issue this was built for: a real ScanJob sat at pairs_scanned=0
 * for several minutes with zero visibility into whether the consumer ever
 * picked up the message, the scanner was detected, or something failed
 * silently before ever calling ssai's fail(). Cheap by construction: the slow
 * `scanimage -L` enumeration belongs to depot:scan-devices on its own schedule,
 * so this only reads what that last wrote. POST /internal/devices/rescan forces
 * a fresh probe when an operator is standing at the machine.
 */
final class StatusController extends AbstractController
{
    public function __construct(
        private readonly ScanJobStatusStore $statusStore,
        private readonly DepotHealthService $health,
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

        $job = $this->statusStore->read();

        return new JsonResponse([
            'ok'               => true,
            'version'          => $this->gitCommit(),
            'depotWebUptimeS'  => $this->depotWebUptimeSeconds(),
            'scanner'          => $this->health->scannerSnapshot(),
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
