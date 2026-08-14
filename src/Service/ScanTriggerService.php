<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Service;

use Survos\DepotBundle\Util\LabelSequencer;
use Survos\DepotBundle\Util\ScanPaths;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * One-shot manual scan: scan a duplex batch (or pair up existing files when
 * $fakeScan is true, for testing without hardware), crop, assign sequential
 * accessions, hand each pair to ssai. Shared by `depot:scan:trigger
 * --target=ssai` and the depot home page's "test the system" form so an
 * operator pressing the button locally behaves identically to the CLI path —
 * this is the manual/local trigger, independent of ssai's scan-job queue
 * (see ScanJobRunner for the ssai-originated push/poll path).
 */
final readonly class ScanTriggerService
{
    public function __construct(
        private readonly ScanService $scanService,
        private readonly AiToolsService $aiToolsService,
        private readonly SsaiScanHubService $ssaiScanHubService,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(FILES_DATA_DIR)%')] private readonly string $filesDataDir,
    ) {
    }

    /**
     * @return array{
     *     ingested: int,
     *     pairs: list<array{accession: string, front: string, back: string}>
     * }
     */
    public function trigger(
        string $intakeCode,
        string $startingAccession,
        string $tenant,
        bool $crop,
        bool $fakeScan,
    ): array {
        $outputDir = ScanPaths::outputDir($this->projectDir, $this->filesDataDir, $intakeCode);

        $pairs = $fakeScan
            ? $this->scanService->pairExistingScans($outputDir)
            : $this->scanService->scanDuplexBatch($outputDir);

        $accession = $startingAccession;
        $sequence = 1;
        $result = [];

        foreach ($pairs as $pair) {
            $analysis = null;

            if ($crop) {
                // Cropping is part of the critical path, not an enhancement:
                // a silently-uncropped scan looks identical to a working one
                // until someone opens the image later. Found live
                // 2026-08-03. Let failures propagate -- HomeController
                // already catches and flashes them as scan_error.
                try {
                    $cropResult = $this->aiToolsService->autocropPair($pair['front'], $pair['back']);
                } catch (\Throwable $e) {
                    throw new \RuntimeException('Crop failed (is ai-tools running?): ' . $e->getMessage(), previous: $e);
                }
                if ($cropResult['ok'] !== true) {
                    throw new \RuntimeException(sprintf(
                        'No crop rect detected for %s (%s)',
                        basename($pair['front']),
                        $cropResult['reason'] ?? 'unknown',
                    ));
                }
                $analysis = $cropResult['analysis'] ?? null;
            }

            $this->ssaiScanHubService->ingestPair(
                $tenant,
                $intakeCode,
                $accession,
                $sequence,
                $pair['front'],
                $pair['back'],
                $analysis,
            );

            $result[] = [
                'accession' => $accession,
                'front'     => basename($pair['front']),
                'back'      => basename($pair['back']),
            ];

            $accession = LabelSequencer::next($accession);
            $sequence += 2;
        }

        return ['ingested' => \count($result), 'pairs' => $result];
    }
}
