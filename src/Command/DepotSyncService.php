<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Command;

use App\Repository\DeviceRepository;
use Survos\DepotBundle\Realtime\Event\DepotHeartbeat;
use Survos\DepotBundle\Realtime\EventPublisherInterface;
use Survos\DepotBundle\Service\DepotHealthService;
use Survos\DepotBundle\Service\DepotIdentity;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * One class, two commands (method-level #[AsCommand], Symfony 8.1 -- see showcase/CONVENTIONS.md "Symfony commands") --
 * heartbeat() and scanDevices() are two halves of the same "report this
 * depot's presence + capabilities" feature, split by speed rather than by
 * class:
 *
 * - heartbeat() is fast (a few HTTP POSTs) and runs often (every ~15s, via
 *   RunCommandMessage in src/Schedule.php) -- it only READS hardware
 *   capabilities from the device table (DeviceRepository), never probes
 *   hardware itself.
 * - scanDevices() is slow (`scanimage -L`'s full backend enumeration
 *   measured ~20s live -- epsonds net+usb, hpaio, and airscan, every time)
 *   and runs rarely (every few minutes) -- it's the only place that ever
 *   actually shells out to scanimage, and WRITES what it finds to the
 *   device table.
 *
 * Same split as ssai's own Depot::$lastSeenAt / heartbeat pattern: a fast
 * path that only ever reads a freshness timestamp, fed by a slower path
 * that's the only one doing real work.
 */
final class DepotSyncService
{
    public function __construct(
        private readonly EventPublisherInterface $events,
        private readonly DepotHealthService $health,
        private readonly DepotIdentity $identity,
        private readonly DeviceRepository $deviceRepository,
        #[Autowire(service: 'monolog.logger.heartbeat')] private readonly LoggerInterface $logger,
        #[Autowire('%env(default::APP_BASE_URL)%')] private readonly ?string $publicUrl,
        #[Autowire('%env(default::DEPOT_IMGPROXY_URL)%')] private readonly ?string $imgproxyUrl,
    ) {
    }

    #[AsCommand('depot:heartbeat', 'Publish this depot\'s presence on the depot.events Redis Pub/Sub channel')]
    public function heartbeat(SymfonyStyle $io): int
    {
        $label = $this->identity->label();

        $url = trim((string) $this->publicUrl);
        if ($url === '') {
            $this->logger->error('heartbeat skipped: APP_BASE_URL not set');
            $io->error('APP_BASE_URL is not set -- nothing to report as this depot\'s reachable URL.');

            return Command::FAILURE;
        }

        $capabilities = $this->health->detectedCapabilities();
        $imgproxyUrl = trim((string) $this->imgproxyUrl);
        $aiToolsReachable = $this->health->aiToolsReachable();

        // Best-effort by contract (see EventPublisherInterface's own docblock)
        // -- a Redis outage here is never a command failure, it's just a
        // missed heartbeat that the next one (~15s later) papers over.
        $this->events->publish(new DepotHeartbeat(
            label: $label,
            url: $url,
            tenants: ['*'],
            capabilities: $capabilities,
            imgproxyUrl: $imgproxyUrl !== '' ? $imgproxyUrl : null,
            aiToolsReachable: $aiToolsReachable,
        ));

        $io->text(sprintf(
            'Heartbeat published as "%s". Capabilities: %s. ai-tools: %s',
            $label,
            $capabilities === [] ? '(none detected)' : implode(', ', $capabilities),
            $aiToolsReachable ? 'reachable' : 'NOT reachable',
        ));

        return Command::SUCCESS;
    }

    /**
     * Model-string substring => capability tag. Only hardware heartbeat()
     * actually needs to know about -- an unrelated device on the network
     * (e.g. a shared office printer) is skipped rather than stored.
     *
     * @var array<string, string>
     */
    private const CAPABILITY_BY_MODEL = [
        'FF-680W' => 'photo_scanner',
    ];

    #[AsCommand('depot:scan-devices', 'Probe for known hardware (scanimage -L) and record what\'s currently present -- slow (~20s), run on its own schedule, not the fast heartbeat path')]
    public function scanDevices(
        SymfonyStyle $io,
        #[Option('Clear all existing device rows first -- use after moving this station to a new network/location')] bool $purge = false,
    ): int {
        if ($purge) {
            $this->deviceRepository->purge();
            $io->text('Purged existing device rows.');
        }

        try {
            $process = new Process(['scanimage', '-L'], timeout: 30);
            $process->run();
        } catch (\Throwable $e) {
            $io->error('scanimage failed: ' . $e->getMessage());

            return Command::FAILURE;
        }

        $found = 0;
        foreach (explode("\n", $process->getOutput()) as $line) {
            if (preg_match('/^device `([^\x27]+)\x27 is a (.+)$/', trim($line), $m) !== 1) {
                continue;
            }
            [, $device, $model] = $m;

            foreach (self::CAPABILITY_BY_MODEL as $needle => $capability) {
                if (str_contains($model, $needle)) {
                    $this->deviceRepository->markSeen($device, $capability, $model);
                    $io->text(sprintf('Found %s (%s) -- %s', $model, $capability, $device));
                    $found++;
                    break;
                }
            }
        }

        $io->text($found > 0
            ? sprintf('%d known device(s) recorded.', $found)
            : 'No known devices found.');

        // Piggybacked on this cadence rather than the 15s heartbeat: it's a
        // ~2s HTTP round-trip, the same reasoning that already keeps
        // scanimage's ~20s enumeration off the fast path. heartbeat() reads
        // the cached result via DepotHealthService::aiToolsReachable().
        $aiToolsReachable = $this->health->refreshAiToolsHealth();
        $io->text('ai-tools: ' . ($aiToolsReachable ? 'reachable' : 'NOT reachable'));

        return Command::SUCCESS;
    }
}
