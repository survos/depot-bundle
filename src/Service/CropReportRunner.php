<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runs ai-tools' crop/deskew call for one pair and reports the result to
 * ssai, success or failure either way -- the exact sequence ScanJobRunner
 * runs inline for every pair as it comes off the feeder, pulled out here so
 * a manual "retry crop" request (RetryCropController, triggered from ssai's
 * capture-page badge when a pair is stuck at cropError) can replay the same
 * attempt against files still sitting on disk, instead of duplicating the
 * ok/fail branching in two places.
 */
final readonly class CropReportRunner
{
    public function __construct(
        private AiToolsService $aiToolsService,
        private SsaiScanHubService $ssaiScanHubService,
        #[Autowire(service: 'monolog.logger.scan_jobs')] private LoggerInterface $logger,
    ) {
    }

    public function run(string $tenant, string $intakeCode, int $frontSequence, string $frontPath, string $backPath): void
    {
        try {
            $result = $this->aiToolsService->autocropPair($frontPath, $backPath);
            if ($result['ok'] === true) {
                $this->ssaiScanHubService->reportCrop($tenant, $intakeCode, $frontSequence, $result['rect'] ?? null, null, $result['analysis'] ?? null);
            } else {
                $reason = 'No crop rect detected (' . ($result['reason'] ?? 'unknown') . ')';
                $this->logger->warning('crop failed', ['intakeCode' => $intakeCode, 'frontSequence' => $frontSequence, 'front' => $frontPath, 'reason' => $reason]);
                $this->ssaiScanHubService->reportCrop($tenant, $intakeCode, $frontSequence, null, $reason, null);
            }
        } catch (\Throwable $e) {
            $reason = 'Crop failed (is ai-tools running?): ' . $e->getMessage();
            $this->logger->warning('crop call failed', ['intakeCode' => $intakeCode, 'frontSequence' => $frontSequence, 'front' => $frontPath, 'error' => $e->getMessage()]);
            try {
                $this->ssaiScanHubService->reportCrop($tenant, $intakeCode, $frontSequence, null, $reason, null);
            } catch (\Throwable) {
                // Both the crop AND the report of its failure failed -- nothing more to do.
            }
        }
    }
}
