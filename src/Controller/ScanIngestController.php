<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Controller;

use App\Entity\Capture;
use App\Service\CaptureStorage;
use App\Workflow\CaptureWFDefinition as WF;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Stand-in for ssai's real /internal/scans (doesn't exist there yet, see
 * docs/scan-jobs-storystation.md). Matches SsaiScanHubService::ingestPair()'s
 * request/response contract exactly, so pointing SSAI_HUB_URL at depot
 * itself for local testing needs zero changes to the scan pipeline --
 * just this receiver, playing the role ssai will eventually play.
 *
 * Persists each side as a Capture row (source=storystation in metadata), so
 * StoryStation scans finally show up in the same "Recent Scans" grid the
 * browser-upload path already populates.
 */
final class ScanIngestController extends AbstractController
{
    public function __construct(
        private readonly CaptureStorage $captureStorage,
        private readonly EntityManagerInterface $entityManager,
        #[Target(WF::WORKFLOW_NAME)] private readonly WorkflowInterface $captureWorkflow,
        #[Autowire('%env(SSAI_HUB_TOKEN)%')] private readonly string $expectedToken,
    ) {
    }

    #[Route('/internal/scans', name: 'internal_scan_ingest', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->expectedToken === '' || $request->headers->get('X-Internal-Token') !== $this->expectedToken) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $tenantId = trim((string) $request->request->get('tenantId', ''));
        $intakeCode = trim((string) $request->request->get('intakeCode', ''));
        $accessionHint = trim((string) $request->request->get('accessionHint', ''));
        $frontSequence = (int) $request->request->get('frontSequence', 0);
        $front = $request->files->get('front');
        $back = $request->files->get('back');

        if ($tenantId === '' || $intakeCode === '' || !$front instanceof UploadedFile || !$back instanceof UploadedFile) {
            return new JsonResponse(['ok' => false, 'error' => 'invalid_payload'], 400);
        }

        // OCR/face-count/type triage from ai-tools' /analyze/autocrop-pair,
        // JSON-encoded by SsaiScanHubService — see that class for why.
        $analysisRaw = $request->request->get('analysis');
        $analysis = \is_string($analysisRaw) ? json_decode($analysisRaw, true) : null;
        $frontAnalysis = \is_array($analysis) ? ($analysis['front'] ?? null) : null;
        $backAnalysis = \is_array($analysis) ? ($analysis['back'] ?? null) : null;

        $frontCapture = $this->storeSide($front, $tenantId, $intakeCode, $accessionHint, $frontSequence, 'front', $frontAnalysis);
        $backCapture = $this->storeSide($back, $tenantId, $intakeCode, $accessionHint, $frontSequence + 1, 'back', $backAnalysis);

        $this->entityManager->persist($frontCapture);
        $this->entityManager->persist($backCapture);
        $this->entityManager->flush();

        // Already forwarded by construction -- there's no separate hub to
        // forward TO here, this controller IS the receiving end (see class
        // docblock). Still walk the real transitions rather than poking
        // $marking directly, so this stays consistent with everywhere else
        // a Capture reaches "forwarded".
        foreach ([$frontCapture, $backCapture] as $capture) {
            $this->captureWorkflow->apply($capture, WF::TRANSITION_FORWARD);
            $this->captureWorkflow->apply($capture, WF::TRANSITION_CONFIRM, ['hubResponse' => ['ok' => true]]);
        }

        return new JsonResponse([
            'ok' => true,
            'intake' => $intakeCode,
            'front' => ['id' => $frontCapture->id, 'filename' => $frontCapture->filename],
            'back' => ['id' => $backCapture->id, 'filename' => $backCapture->filename],
        ], 201);
    }

    /**
     * @param array<string, mixed>|null $analysis
     */
    private function storeSide(
        UploadedFile $file,
        string $tenantId,
        string $intakeCode,
        string $accessionHint,
        int $sequence,
        string $side,
        ?array $analysis,
    ): Capture {
        $stored = $this->captureStorage->store($file, $intakeCode . '-' . $accessionHint . '-' . $side);

        $metadata = [
            'source'     => 'storystation',
            'intakeCode' => $intakeCode,
            'accession'  => $accessionHint,
            'sequence'   => $sequence,
            'side'       => $side,
        ];
        if ($analysis !== null) {
            $metadata['analysis'] = $analysis;
        }

        return new Capture(
            tenantId: $tenantId,
            stationId: 'storystation',
            filename: $stored['filename'],
            mimeType: $stored['mimeType'],
            sizeBytes: $stored['sizeBytes'],
            localPath: $stored['absolutePath'],
            publicPath: $stored['publicPath'],
            metadata: $metadata,
            intakeCode: $intakeCode,
            side: $side,
        );
    }
}
