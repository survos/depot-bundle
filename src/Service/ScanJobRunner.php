<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Service;

use Survos\DepotBundle\Message\RunScanJobMessage;
use Survos\DepotBundle\Util\LabelSequencer;
use Survos\DepotBundle\Util\ScanPaths;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The actual StoryStation batch loop: scan duplex pairs from the ADF until
 * ssai marks the job stopping, cropping and handing each pair to ssai as it
 * comes off the feeder. Shared by both entry points that can start a job —
 * `depot:poll-scan-jobs` (claims jobs itself, calls run() directly) and
 * ssai's push trigger (calls run() via the __invoke message handler below)
 * — so the two never drift out of sync.
 */
final readonly class ScanJobRunner
{
    public function __construct(
        private readonly ScanService $scanService,
        private readonly CropReportRunner $cropReportRunner,
        private readonly SsaiScanHubService $ssaiScanHubService,
        private readonly SsaiScanJobHubService $ssaiScanJobHubService,
        private readonly ScanJobStatusStore $statusStore,
        #[Autowire(service: 'monolog.logger.scan_jobs')] private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(FILES_DATA_DIR)%')] private readonly string $filesDataDir,
        #[Autowire('%env(default::SCAN_JOB_AUTO_STOP_SECONDS)%')] private readonly ?string $autoStopAfterSecondsRaw = null,
    ) {
    }

    /**
     * Consumes a job pushed by ssai's Start button (see
     * ScanJobTriggerController). No dedicated handler class needed — modern
     * Symfony (8.1) message handlers are pure attribute-based, independent
     * of class location or interface.
     */
    #[AsMessageHandler]
    public function __invoke(RunScanJobMessage $message): void
    {
        $this->run(
            [
                'id'            => $message->jobId,
                'intakeCode'    => $message->intakeCode,
                'startingLabel' => $message->startingLabel,
            ],
            $message->tenantId,
            true,
            1,
        );
    }

    /**
     * @param array{id: int, tenantId?: string, intakeCode: string, status?: string, startingLabel: ?string} $job
     */
    public function run(array $job, string $tenant, bool $crop, int $retryInterval): void
    {
        $jobId = $job['id'];
        $intakeCode = $job['intakeCode'];
        $outputDir = ScanPaths::outputDir($this->projectDir, $this->filesDataDir, $intakeCode);
        $sequence = 1;
        $accessionLabel = $job['startingLabel'] ?? '1';
        $autoStopAfterSeconds = ((int) $this->autoStopAfterSecondsRaw) > 0 ? (int) $this->autoStopAfterSecondsRaw : 30;
        $waitingSince = null;
        $this->logger->info('starting label', ['jobId' => $jobId, 'startingLabel' => $accessionLabel, 'autoStopAfterSeconds' => $autoStopAfterSeconds]);
        $this->statusStore->update([
            'jobId'      => $jobId,
            'intakeCode' => $intakeCode,
            'status'     => 'active',
            'lastError'  => null, // otherwise a past job's error lingers in /internal/status forever
            'startedAt'  => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);

        while (true) {
            // Status-check hits ssai over its own tunnel every loop iteration
            // -- far more exposure to a transient network blip than the
            // per-pair scan-hub call. A single 502 used to kill the whole
            // session (hit twice in one real scanning run) even though
            // ssai/mediary/depot were all actually fine seconds later.
            // Three quick retries absorb that without masking a genuinely
            // dead ssai (which will keep failing and still stop the job).
            $status = null;
            $lastStatusError = null;
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    $status = $this->ssaiScanJobHubService->status($jobId);
                    $lastStatusError = null;
                    break;
                } catch (\Throwable $e) {
                    $lastStatusError = $e;
                    $this->logger->warning('status check failed, retrying', [
                        'jobId' => $jobId, 'attempt' => $attempt, 'error' => $e->getMessage(),
                    ]);
                    if ($attempt < 3) {
                        sleep(2);
                    }
                }
            }
            if ($lastStatusError !== null) {
                $this->logger->error('status check failed after retries, stopping', ['jobId' => $jobId, 'error' => $lastStatusError->getMessage()]);
                $this->statusStore->update(['status' => 'error', 'lastError' => $lastStatusError->getMessage()]);
                // Tell ssai depot gave up -- otherwise its own scan_job row
                // sits at "active" forever (this is the same status-check
                // call that just failed 3 times, so this attempt is also
                // best-effort; ssai's own 90s staleness check is the real
                // backstop if it fails too).
                try {
                    $this->ssaiScanJobHubService->fail($jobId, $lastStatusError->getMessage());
                } catch (\Throwable) {
                }
                return;
            }
            $this->logger->debug('status check', ['jobId' => $jobId, 'status' => $status]);

            if ($status !== 'active') {
                $this->logger->info('job no longer active, stopping', ['jobId' => $jobId, 'status' => $status]);
                $this->ssaiScanJobHubService->markStopped($jobId);
                $this->statusStore->update(['status' => 'stopped']);
                return;
            }

            // Streaming, not scanDuplexBatch()'s old mustRun()-then-glob() --
            // that waited for scanimage to finish the operator's WHOLE
            // feeder-load before returning anything, so nothing appeared on
            // the capture page until the entire stack was done (found live:
            // multi-second-to-minutes of dead air), and a hardware fault
            // partway through lost every already-scanned page in that batch
            // (2026-08-13: "sane_start: Error during device I/O" after 10
            // pages, zero of them ingested). scanDuplexBatchStream() yields
            // each pair the instant its files exist, and a mid-batch failure
            // only loses what hadn't been scanned yet -- see its own docblock.
            $pairsThisBatch = 0;
            $pendingCrops = [];

            try {
                foreach ($this->scanService->scanDuplexBatchStream($outputDir) as $pair) {
                    $waitingSince = null;
                    $pairsThisBatch++;

                    // Raw hand-off first, before crop -- this IS the
                    // critical path (a lost scan is a lost scan). Pushed the
                    // moment this pair's files exist, not after the rest of
                    // the batch also finishes scanning.
                    try {
                        $this->ssaiScanHubService->ingestPair(
                            $tenant,
                            $intakeCode,
                            $accessionLabel,
                            $sequence,
                            $pair['front'],
                            $pair['back'],
                        );
                        $this->logger->debug('pair pushed to ssai', ['jobId' => $jobId, 'sequence' => $sequence, 'accessionLabel' => $accessionLabel]);
                        $this->statusStore->recordSuccessfulScan();
                    } catch (\Throwable $e) {
                        $this->logger->error('ssai hand-off failed', ['jobId' => $jobId, 'sequence' => $sequence, 'error' => $e->getMessage()]);
                        $this->statusStore->update(['status' => 'failed', 'lastError' => $e->getMessage()]);
                        $this->ssaiScanJobHubService->fail($jobId, $e->getMessage());
                        return;
                    }

                    if ($crop) {
                        // Deferred, not run inline here -- ai-tools' crop+OCR
                        // call measured ~2.7s live, and running it before
                        // moving on to the NEXT pair's ingest was adding that
                        // same delay between every photo appearing on the
                        // capture page (found live 2026-08-13). Collected
                        // here, run as its own pass once this batch's raw
                        // ingest is done, so it never blocks a still-loading
                        // scan. Still non-fatal either way -- see
                        // CropReportRunner's docblock.
                        $pendingCrops[] = ['sequence' => $sequence, 'pair' => $pair];
                    }

                    $sequence += 2;
                    $accessionLabel = LabelSequencer::next($accessionLabel);
                }
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'No paper loaded')) {
                    // Auto-stop after continuous idle, not a manual Stop
                    // click -- the natural workflow is "load a stack, hit
                    // Scan," not "hope the operator remembers to reload the
                    // feeder before this ever ends." $waitingSince tracks
                    // continuous idle time only; a real batch scanning
                    // successfully resets it above, so this is "how long
                    // since paper was LAST seen," not cumulative across the
                    // whole job. Only reached with zero pairs scanned --
                    // scanDuplexBatchStream() returns cleanly, no throw, for
                    // the normal "feeder ran dry after N real pages" case.
                    $waitingSince ??= time();
                    $idleSeconds = time() - $waitingSince;
                    if ($idleSeconds >= $autoStopAfterSeconds) {
                        $this->logger->info('feeder idle for {idleSeconds}s, auto-stopping', ['jobId' => $jobId, 'idleSeconds' => $idleSeconds, 'autoStopAfterSeconds' => $autoStopAfterSeconds]);
                        $this->ssaiScanJobHubService->markStopped($jobId);
                        $this->statusStore->update(['status' => 'stopped']);
                        return;
                    }

                    $this->logger->debug('feeder empty, waiting', ['jobId' => $jobId, 'retryInterval' => $retryInterval, 'idleSeconds' => $idleSeconds]);
                    $this->statusStore->update(['status' => 'waiting_for_paper']);
                    sleep($retryInterval);
                    continue;
                }

                // A real (non "no paper") failure -- still stops the job as
                // before, but anything already yielded above was already
                // ingested by this point, so it's not lost the way it used
                // to be when this exception used to fire before any pair
                // was ever returned.
                $this->logger->error('scan failed', ['jobId' => $jobId, 'error' => $e->getMessage(), 'pairsIngestedBeforeFailure' => $pairsThisBatch]);
                $this->statusStore->update(['status' => 'failed', 'lastError' => $e->getMessage()]);
                $this->ssaiScanJobHubService->fail($jobId, $e->getMessage());
                return;
            }

            $this->logger->info('batch scanned', ['jobId' => $jobId, 'pairs' => $pairsThisBatch]);
            $this->statusStore->update(['status' => 'active']);

            foreach ($pendingCrops as $pending) {
                $this->cropReportRunner->run($tenant, $intakeCode, $pending['sequence'], $pending['pair']['front'], $pending['pair']['back']);
            }

            try {
                $this->ssaiScanJobHubService->heartbeat($jobId, $pairsThisBatch);
                $this->logger->debug('heartbeat sent', ['jobId' => $jobId, 'pairsThisBatch' => $pairsThisBatch]);
            } catch (\Throwable $e) {
                $this->logger->warning('heartbeat failed, continuing', ['jobId' => $jobId, 'error' => $e->getMessage()]);
            }
        }
    }
}
