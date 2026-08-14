<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Controller;

use Survos\DepotBundle\Message\RunScanJobMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * ssai's Start button calls this directly instead of waiting for depot's
 * poll loop (see docs/scan-jobs-storystation.md). Depot only needs to be
 * reachable for this one endpoint — everything else (ingestPair, status,
 * heartbeat, fail) still runs the other direction, depot calling out to
 * ssai, unchanged.
 *
 * Dispatches to the `scan_jobs` doctrine transport rather than running the
 * job inline: a job can run for as long as the operator keeps feeding the
 * ADF, far longer than an HTTP request should block for. `bin/scan-worker.sh`
 * must be running on this machine to actually pick it up.
 */
final class ScanJobTriggerController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        #[Autowire('%env(DEPOT_INBOUND_TOKEN)%')] private readonly string $expectedToken,
    ) {
    }

    #[Route('/internal/scan-jobs/trigger', name: 'internal_scan_job_trigger', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->expectedToken === '' || $request->headers->get('X-Internal-Token') !== $this->expectedToken) {
            return new JsonResponse(['ok' => false, 'reason' => 'unauthorized'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return new JsonResponse(['ok' => false, 'reason' => 'invalid_json'], 400);
        }

        $jobId = $payload['jobId'] ?? null;
        $tenantId = $payload['tenantId'] ?? null;
        $intakeCode = $payload['intakeCode'] ?? null;

        if (!\is_int($jobId) || !\is_string($tenantId) || $tenantId === '' || !\is_string($intakeCode) || $intakeCode === '') {
            return new JsonResponse(['ok' => false, 'reason' => 'invalid_payload'], 400);
        }

        $startingLabel = $payload['startingLabel'] ?? null;
        if ($startingLabel !== null && !\is_string($startingLabel)) {
            return new JsonResponse(['ok' => false, 'reason' => 'invalid_payload'], 400);
        }

        $this->messageBus->dispatch(new RunScanJobMessage($jobId, $tenantId, $intakeCode, $startingLabel));

        return new JsonResponse(['ok' => true, 'accepted' => true], 202);
    }
}
