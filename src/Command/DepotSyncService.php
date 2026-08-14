<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Command;

use App\Repository\DeviceRepository;
use Survos\DepotBundle\Service\DepotHealthService;
use Survos\DepotBundle\Service\DepotIdentity;
use Survos\DepotBundle\Service\SsaiHubBroadcastList;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

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
        private readonly HttpClientInterface $httpClient,
        private readonly SsaiHubBroadcastList $hubs,
        private readonly DepotHealthService $health,
        private readonly DepotIdentity $identity,
        private readonly DeviceRepository $deviceRepository,
        #[Autowire(service: 'monolog.logger.heartbeat')] private readonly LoggerInterface $logger,
        #[Autowire('%env(default::APP_BASE_URL)%')] private readonly ?string $publicUrl,
        #[Autowire('%env(default::SSAI_HUB_TOKEN)%')] private readonly ?string $token,
        #[Autowire('%env(default::DEPOT_IMGPROXY_URL)%')] private readonly ?string $imgproxyUrl,
    ) {
    }

    #[AsCommand('depot:heartbeat', 'Broadcast this depot\'s presence to every configured ssai hub')]
    public function heartbeat(SymfonyStyle $io): int
    {
        $label = $this->identity->label();

        $url = trim((string) $this->publicUrl);
        if ($url === '') {
            $this->logger->error('heartbeat skipped: APP_BASE_URL not set');
            $io->error('APP_BASE_URL is not set -- nothing to report as this depot\'s reachable URL.');

            return Command::FAILURE;
        }

        $hubs = $this->hubs->all();
        if ($hubs === []) {
            $this->logger->warning('heartbeat skipped: no hubs configured');
            $io->warning('No hub configured (SSAI_HUB_URL / SSAI_HUB_BROADCAST_URLS both empty) -- nothing to broadcast to.');

            return Command::SUCCESS;
        }

        $capabilities = $this->health->detectedCapabilities();
        $imgproxyUrl = trim((string) $this->imgproxyUrl);
        $payload = [
            'label'        => $label,
            'url'          => $url,
            'tenants'      => ['*'],
            'capabilities' => $capabilities,
            'imgproxyUrl'  => $imgproxyUrl !== '' ? $imgproxyUrl : null,
        ];
        $ok = 0;

        foreach ($hubs as $hub) {
            try {
                $status = $this->httpClient->request('POST', $hubUrl = $hub . '/internal/depots/heartbeat', [
                    'json'    => $payload,
                    'headers' => ['X-Internal-Token' => (string) $this->token],
                    'timeout' => 3.0,
                ])->getStatusCode();
                $this->logger->debug('Dispatching heartbeat to ' . $hubUrl, $payload);

                if ($status >= 200 && $status < 300) {
                    $ok++;
                    $this->logger->debug('heartbeat sent', ['hub' => $hub, 'label' => $label]);
                } else {
                    $this->logger->warning('heartbeat rejected', ['hub' => $hub, 'status' => $status]);
                }
            } catch (ExceptionInterface $e) {
                $this->logger->debug('heartbeat unreachable, skipping', ['hub' => $hub, 'error' => $e->getMessage()]);
            }
        }

        $io->text(sprintf(
            'Heartbeat sent to %d/%d hub(s) as "%s". Capabilities: %s',
            $ok,
            \count($hubs),
            $label,
            $capabilities === [] ? '(none detected)' : implode(', ', $capabilities),
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

        return Command::SUCCESS;
    }
}
