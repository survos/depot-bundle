<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Command;

use App\Repository\DeviceRepository;
use Survos\DepotBundle\Realtime\Event\DepotHeartbeat;
use Survos\DepotBundle\Realtime\EventPublisherInterface;
use Survos\DepotBundle\Service\DepotHealthService;
use Survos\DepotBundle\Service\DepotIdentity;
use Survos\DepotBundle\Service\SsaiHubBroadcastList;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Survos\DepotBundle\Service\ScanJobStatusStore;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\Transport\Receiver\MessageCountAwareInterface;
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
        private readonly SsaiHubBroadcastList $hubs,
        #[Autowire(service: 'ssai.hub')] private readonly HttpClientInterface $ssaiHubClient,
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(default::SSAI_HUB_TOKEN)%')] private readonly ?string $ssaiHubToken,
        private readonly ScanJobStatusStore $scanJobStatus,
        #[Autowire(service: 'messenger.transport.scan_jobs')] private readonly MessageCountAwareInterface $scanJobsTransport,
    ) {
    }

    #[AsCommand('depot:heartbeat', 'Report this depot\'s presence to every configured ssai hub (and publish on the depot.events Redis channel)')]

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

        $payload = [
            'label' => $label,
            'url' => $url,
            'tenants' => ['*'],
            'capabilities' => $capabilities,
            'imgproxyUrl' => $imgproxyUrl !== '' ? $imgproxyUrl : null,
            'aiToolsReachable' => $aiToolsReachable,
            // Everything below this line used to be reachable ONLY by tunnelling
            // into /internal/status with the station's token. That made the most
            // useful diagnostic in the system invisible to the system that needs
            // it: when a scan POST failed, the hub returned the error and then
            // had no idea it had happened, while the operator watched a capture
            // page that showed nothing at all -- no images, no error, no reason.
            //
            // It rides the heartbeat because the heartbeat already exists, already
            // runs every 15s, and already reaches every hub. No new transport, no
            // new auth, nothing new to expose.
            //
            // Deliberately NOT here: a live scanner probe. scanimage -L's backend
            // enumeration measures ~20s, which is why depot:scan-devices owns it
            // on a slower schedule. `capabilities` above already reflects the
            // cached result; a hub that wants a fresh answer asks /internal/status
            // with ?probe=1 and pays the 20s itself.
            //
            // Also not here: whether the scheduler is running. This command IS the
            // scheduler's work -- if the hub is reading these fields, the answer is
            // yes. Its absence is the signal, which is how a dead scheduler was
            // actually found: heartbeat frozen while the web app stayed up.
            'status' => [
                'scanWorkerActive' => $this->health->scanWorkerActive(),
                'scanJobsQueued' => $this->scanJobsQueued(),
                'currentJob' => $this->currentJob(),
            ],
        ];

        // The authoritative write. Each hub records this over HTTP, so a station
        // and its hub only need the tunnel they already use for scan files --
        // no shared broker, and nothing to expose.
        //
        // This POST was removed at some point and only the Redis publish below
        // was left, which quietly broke every deployment where depot and ssai are
        // not the same machine: Redis pub/sub drops a message when nobody is
        // subscribed, so depot reported success every 15s while the hub's
        // Depot.lastSeenAt never moved and its capture screen kept "Start
        // Scanning" disabled, with no error on either side. A depot on a
        // ThinkPad at a customer site and a hub in production can never share
        // 127.0.0.1:6379, and a cloudflared HTTP ingress will not carry 6379.
        $accepted = $this->broadcastHeartbeat($payload);

        // Best-effort by contract (see EventPublisherInterface's own docblock)
        // -- a Redis outage here is never a command failure, it's just a
        // missed heartbeat that the next one (~15s later) papers over. Kept as
        // the low-latency trigger: where both processes share a Redis it flips
        // the hub's depot-online dot in ~1.5s instead of waiting for a poll.
        $this->events->publish(new DepotHeartbeat(
            label: $label,
            url: $url,
            tenants: $payload['tenants'],
            capabilities: $capabilities,
            imgproxyUrl: $payload['imgproxyUrl'],
            aiToolsReachable: $aiToolsReachable,
        ));

        $hubCount = \count($this->hubs->all());
        $io->text(sprintf(
            'Heartbeat sent as "%s" to %d/%d hub%s. Capabilities: %s. ai-tools: %s',
            $label,
            $accepted,
            $hubCount,
            $hubCount === 1 ? '' : 's',
            $capabilities === [] ? '(none detected)' : implode(', ', $capabilities),
            $aiToolsReachable ? 'reachable' : 'NOT reachable',
        ));

        // A heartbeat no hub accepted is a failure worth surfacing -- silence
        // here is what made this invisible for so long. Redis still got its
        // publish, so nothing is lost by reporting it.
        return $accepted === 0 && $hubCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * POSTs the heartbeat to every hub in SsaiHubBroadcastList, best-effort.
     *
     * All hubs, not just primary(): the list exists to be broadcast to -- see
     * DepotHealthService::ssaiHubStatuses(), whose own docblock already calls
     * these "every hub this depot broadcasts to (heartbeat + scan results)".
     * A station demoing at a site may legitimately report to production and a
     * dev hub at once, and one unreachable hub must not stop the others.
     *
     * @param array<string,mixed> $payload
     *
     * @return int how many hubs accepted it
     */
    private function broadcastHeartbeat(array $payload): int
    {
        $primary = $this->hubs->primary();
        $accepted = 0;

        foreach ($this->hubs->all() as $hubUrl) {
            $isPrimary = $hubUrl === $primary;

            try {
                // The primary goes through the pre-configured `ssai.hub` scoped
                // client so it picks up base_uri, auth and (in dev) the proxy
                // that makes *.wip resolve from PHP; extras use the plain client
                // with the same shared token. Same split as ssaiHubStatuses().
                $response = $isPrimary
                    ? $this->ssaiHubClient->request('POST', '/internal/depots/heartbeat', [
                        'json' => $payload,
                        'timeout' => 5.0,
                    ])
                    : $this->httpClient->request('POST', $hubUrl . '/internal/depots/heartbeat', [
                        'json' => $payload,
                        'headers' => ['X-Internal-Token' => (string) $this->ssaiHubToken],
                        'timeout' => 5.0,
                    ]);

                $status = $response->getStatusCode();
                if ($status >= 200 && $status < 300) {
                    ++$accepted;

                    continue;
                }

                $this->logger->warning('heartbeat: hub {hub} answered {status}', [
                    'hub' => $hubUrl,
                    'status' => $status,
                ]);
            } catch (HttpExceptionInterface $e) {
                $this->logger->warning('heartbeat: hub {hub} unreachable: {err}', [
                    'hub' => $hubUrl,
                    'err' => $e->getMessage(),
                ]);
            }
        }

        return $accepted;
    }

    /**
     * Model-string substring => capability tag. Only hardware heartbeat()
     * actually needs to know about -- an unrelated device on the network
     * (e.g. a shared office printer) is skipped rather than stored.
     *
     * @var array<string, string>
     */
    private const CAPABILITY_BY_MODEL = [
        // Sheet-fed duplex ADF: scans front and back in one pass, which is what
        // the front/back intake profiles are built around.
        'FF-680W' => 'photo_scanner',
        // Flatbed multifunction, reached over the network (escl/airscan). It is
        // a photo_scanner in the sense that matters here -- depot can drive it --
        // but it has no ADF, so a front/back profile means two passes by hand
        // rather than one duplex sweep.
        'ET-3700' => 'photo_scanner',
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
            // Not anchored at line start on purpose. A backend that probes an HTTP
            // endpoint can dump a whole HTML page into this output with no trailing
            // newline, so the first real device arrives welded to it:
            //
            //   </body></HTML>device `v4l:/dev/video0' is a ...
            //
            // Anchored, that line never matched and the first device on the bus was
            // silently invisible -- observed live, where it happened to be a webcam
            // and could just as easily have been the scanner.
            if (preg_match('/device `([^\x27]+)\x27 is a (.+)$/', trim($line), $m) !== 1) {
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

    /**
     * Queue depth, defensively. A transport that cannot be counted must not take
     * the heartbeat down with it -- presence is the heartbeat's primary job and
     * this block is an extra.
     */
    private function scanJobsQueued(): ?int
    {
        try {
            return $this->scanJobsTransport->getMessageCount();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The scan job this station last worked on, including the error that ended
     * it. lastError is the field that matters: it is where "HTTP/2 502 returned
     * for /internal/scans" lives, and until now the hub that issued that 502
     * could not see it.
     *
     * @return array<string, mixed>
     */
    private function currentJob(): array
    {
        try {
            $job = $this->scanJobStatus->read();
        } catch (\Throwable) {
            return [];
        }

        return [
            'jobId' => $job['jobId'] ?? null,
            'intakeCode' => $job['intakeCode'] ?? null,
            'status' => $job['status'] ?? null,
            'lastError' => $job['lastError'] ?? null,
            'startedAt' => $job['startedAt'] ?? null,
            'lastActivityAt' => $job['lastActivityAt'] ?? null,
            'lastSuccessfulScanAt' => $job['lastSuccessfulScanAt'] ?? null,
        ];
    }
}
