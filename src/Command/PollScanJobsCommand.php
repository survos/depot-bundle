<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Command;

use Survos\DepotBundle\Service\ScanJobRunner;
use Survos\DepotBundle\Service\SsaiScanJobHubService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * "StoryStation" — polls ssai for a scan job, then runs continuous ADF
 * batches (reload the feeder, it keeps going) for that intake until ssai
 * marks the job stopping. Depot never needs to be reachable from ssai; it
 * only ever polls out, matching the existing depot:poll-print-jobs pattern.
 *
 * This is the critical depot<->ssai communications link — everything logs
 * to the scan_jobs channel (var/log/scan-jobs.log), tail it to see exactly
 * what's happening: `tail -f var/log/scan-jobs.log`.
 */
#[AsCommand('depot:poll-scan-jobs', 'Poll ssai for a StoryStation scan job and run continuous ADF batches until stopped')]
final readonly class PollScanJobsCommand
{
    public function __construct(
        private readonly ScanJobRunner $scanJobRunner,
        private readonly SsaiScanJobHubService $ssaiScanJobHubService,
        #[Autowire(service: 'monolog.logger.scan_jobs')] private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option('ssai tenant id to poll for')] string $tenant = 'default',
        #[Option('Identifier this depot reports as')] string $depotId = 'depot',
        #[Option('Crop/deskew each pair via ai-tools before hand-off')] bool $crop = true,
        #[Option('Seconds between claim polls when idle')] int $pollInterval = 1,
        #[Option('Seconds to wait between empty-feeder retries within an active job')] int $retryInterval = 1,
        #[Option('Keep polling for new jobs after one finishes')] bool $loop = false,
    ): int {
        $this->logger->info('poll-scan-jobs starting', ['tenant' => $tenant, 'depotId' => $depotId, 'loop' => $loop, 'pollInterval' => $pollInterval]);

        do {
            $this->logger->debug('polling for claimable job', ['tenant' => $tenant]);

            try {
                $job = $this->ssaiScanJobHubService->claimOne($tenant, $depotId);
            } catch (\Throwable $e) {
                $this->logger->error('claim request failed', ['error' => $e->getMessage()]);
                $io->error('Failed to reach ssai: ' . $e->getMessage());
                if (!$loop) {
                    return Command::FAILURE;
                }
                sleep($pollInterval);
                continue;
            }

            if ($job === null) {
                $io->comment('No pending scan job.');
                if (!$loop) {
                    return Command::SUCCESS;
                }
                sleep($pollInterval);
                continue;
            }

            $this->logger->info('claimed scan job', ['jobId' => $job['id'], 'intakeCode' => $job['intakeCode']]);
            $io->title(sprintf('Claimed scan job #%d for intake "%s"', $job['id'], $job['intakeCode']));
            $this->scanJobRunner->run($job, $tenant, $crop, $retryInterval);
            $io->text('Job finished — see var/log/scan-jobs.log for the full run detail.');
        } while ($loop);

        return Command::SUCCESS;
    }
}
