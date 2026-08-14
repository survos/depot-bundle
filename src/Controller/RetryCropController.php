<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Controller;

use Survos\DepotBundle\Service\CropReportRunner;
use Survos\DepotBundle\Util\ScanPaths;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Re-runs ai-tools' crop/deskew for one pair already on disk -- the
 * capture-page "retry crop" badge action for a pair stuck at cropError
 * (ScanIngestController::updateCrop() sets it, see that method's docblock
 * on why it's surfaced rather than swallowed). No re-scan involved: the
 * raw page-*.jpg files from the original scan are untouched on disk (this
 * is deliberately narrower than ScanDeleteController's "Clear All" purge --
 * that throws the files away, this just reprocesses them), so the same
 * front/back pair can be re-cropped as many times as needed. Same token
 * gate as ScanDeleteController/ScanFileController.
 */
final class RetryCropController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(DEPOT_INBOUND_TOKEN)%')] private readonly string $expectedToken,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(FILES_DATA_DIR)%')] private readonly string $filesDataDir,
        private readonly CropReportRunner $cropReportRunner,
    ) {
    }

    #[Route(
        '/internal/scans/{intakeCode}/retry-crop',
        name: 'internal_scans_retry_crop',
        requirements: ['intakeCode' => '[\w.-]+'],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $intakeCode): JsonResponse
    {
        if ($this->expectedToken === '' || $request->headers->get('X-Internal-Token') !== $this->expectedToken) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $body = json_decode($request->getContent(), true);
        if (!\is_array($body)) {
            $body = [];
        }

        $tenantId = (string) ($body['tenantId'] ?? '');
        $frontSequence = (int) ($body['frontSequence'] ?? 0);
        $frontFilename = (string) ($body['frontFilename'] ?? '');
        $backFilename = (string) ($body['backFilename'] ?? '');

        if ($tenantId === '' || $frontSequence <= 0 || $frontFilename === '' || $backFilename === '') {
            return new JsonResponse(['ok' => false, 'error' => 'missing tenantId/frontSequence/frontFilename/backFilename'], 400);
        }

        $dir = ScanPaths::outputDir($this->projectDir, $this->filesDataDir, $intakeCode);
        $frontPath = rtrim($dir, '/') . '/' . basename($frontFilename);
        $backPath = rtrim($dir, '/') . '/' . basename($backFilename);

        if (!is_file($frontPath) || !is_file($backPath)) {
            return new JsonResponse(['ok' => false, 'error' => 'scan files no longer on disk (purged?)'], 404);
        }

        // Synchronous -- ai-tools' call measures ~2.7s live, fine for a
        // manual single-click retry (the operator is already looking at the
        // badge, waiting a few seconds for it to update is the expected UX,
        // unlike the batch loop where this runs alongside continuous scanning).
        $this->cropReportRunner->run($tenantId, $intakeCode, $frontSequence, $frontPath, $backPath);

        return new JsonResponse(['ok' => true]);
    }
}
