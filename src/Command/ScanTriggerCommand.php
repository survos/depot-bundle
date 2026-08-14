<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Command;

use Survos\DepotBundle\Service\AiToolsService;
use Survos\DepotBundle\Service\MediaryService;
use Survos\DepotBundle\Service\ScanService;
use Survos\DepotBundle\Service\ScanTriggerService;
use Survos\DepotBundle\Util\LabelSequencer;
use Survos\DepotBundle\Util\ScanPaths;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('depot:scan:trigger', 'Trigger a duplex batch scan and hand the images off to ssai (or mediary)')]
final readonly class ScanTriggerCommand
{
    public function __construct(
        private readonly ScanService $scanService,
        private readonly AiToolsService $aiToolsService,
        private readonly ScanTriggerService $scanTriggerService,
        private readonly MediaryService $mediaryService,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(FILES_DATA_DIR)%')] private readonly string $filesDataDir,
        #[Autowire('%env(APP_BASE_URL)%')] private readonly string $appBaseUrl,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Intake code this batch belongs to (e.g. an envelope/accession-lot identifier)')] string $intakeCode,
        #[Option('First accession number/code to assign; subsequent photos increment from it')] string $startingAccession = '1',
        #[Option('Hand-off target: ssai or mediary')] string $target = 'ssai',
        #[Option('ssai tenant id (only used when target=ssai)')] string $tenant = 'default',
        #[Option('Mediary client key (only used when target=mediary)')] string $client = 'depot',
        #[Option('Block until the target finishes processing instead of queuing async')] bool $sync = true,
        #[Option('Crop/deskew each pair via ai-tools before hand-off')] bool $crop = true,
        #[Option('Skip the scanner; pair up page-*.jpg files already sitting in the output dir (for testing without hardware)')] bool $fakeScan = false,
    ): int {
        if (!\in_array($target, ['ssai', 'mediary'], true)) {
            $io->error('--target must be one of: ssai, mediary.');
            return Command::INVALID;
        }

        $io->title(sprintf('Scanning intake "%s"', $intakeCode) . ($fakeScan ? ' (fake scan — no hardware)' : ''));

        if ($target === 'ssai') {
            try {
                $result = $this->scanTriggerService->trigger($intakeCode, $startingAccession, $tenant, $crop, $fakeScan);
            } catch (\Throwable $e) {
                $io->error($e->getMessage());
                return Command::FAILURE;
            }

            $io->text(sprintf('Scanned %d photo(s) (%d sides).', $result['ingested'], $result['ingested'] * 2));

            $io->table(['accession', 'front', 'back'], array_map(
                static fn(array $p) => [$p['accession'], $p['front'], $p['back']],
                $result['pairs'],
            ));

            $io->success(sprintf('Handed off %d image pair(s) to ssai for intake "%s".', $result['ingested'], $intakeCode));

            return Command::SUCCESS;
        }

        $outputDir = ScanPaths::outputDir($this->projectDir, $this->filesDataDir, $intakeCode);

        try {
            $pairs = $fakeScan
                ? $this->scanService->pairExistingScans($outputDir)
                : $this->scanService->scanDuplexBatch($outputDir);
        } catch (\Throwable $e) {
            $io->error('Scan failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->text(sprintf('Scanned %d photo(s) (%d sides).', \count($pairs), \count($pairs) * 2));

        if ($crop) {
            foreach ($pairs as $pair) {
                try {
                    $cropResult = $this->aiToolsService->autocropPair($pair['front'], $pair['back']);
                    if ($cropResult['ok'] !== true) {
                        $io->note(sprintf(
                            'No crop for %s (%s) — kept full scan-bed image.',
                            basename($pair['front']),
                            $cropResult['reason'] ?? 'unknown',
                        ));
                    }
                } catch (\Throwable $e) {
                    // Cropping is an enhancement, not a correctness requirement —
                    // never block the hand-off on ai-tools being unreachable.
                    $io->warning(sprintf(
                        'Crop failed for %s, keeping full scan-bed image: %s',
                        basename($pair['front']),
                        $e->getMessage(),
                    ));
                }
            }
        }

        $accession = $startingAccession;
        $withAccessions = [];
        $sequence = 1;
        foreach ($pairs as $pair) {
            $withAccessions[] = [
                'front'         => $pair['front'],
                'back'          => $pair['back'],
                'accession'     => $accession,
                'frontSequence' => $sequence,
            ];
            $accession = LabelSequencer::next($accession);
            $sequence += 2;
        }

        $io->table(['accession', 'front', 'back'], array_map(
            static fn(array $p) => [$p['accession'], basename($p['front']), basename($p['back'])],
            $withAccessions,
        ));

        $withUrls = array_map(fn(array $p) => [
            'front'     => $this->toPublicUrl($p['front']),
            'back'      => $this->toPublicUrl($p['back']),
            'accession' => $p['accession'],
        ], $withAccessions);

        try {
            $result = $this->mediaryService->ensureScanAssets($client, $intakeCode, $withUrls, $sync);
        } catch (\Throwable $e) {
            $io->error('Mediary hand-off failed (images remain on disk at ' . $outputDir . '): ' . $e->getMessage());
            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Handed off %d image(s) to mediary for intake "%s".',
            \count($result['media'] ?? []),
            $intakeCode,
        ));

        return Command::SUCCESS;
    }

    /**
     * Assumes FILES_DATA_DIR lives under public/, matching the .env default
     * (public/uploads) — this is what Symfony's built-in dev server (and any
     * front-controller-backed prod server) already serves directly. If depot
     * grows a dedicated file-serving endpoint per AGENTS.md, swap this for that.
     */
    private function toPublicUrl(string $absolutePath): string
    {
        $publicRoot = rtrim($this->projectDir, '/') . '/public/';
        $relative = str_starts_with($absolutePath, $publicRoot)
            ? substr($absolutePath, \strlen($publicRoot))
            : basename($absolutePath);

        return rtrim($this->appBaseUrl, '/') . '/' . $relative;
    }
}
