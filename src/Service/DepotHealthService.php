<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Service;

use App\Entity\Device;
use App\Repository\DeviceRepository;
use Psr\Cache\CacheItemPoolInterface;
use Survos\DepotBundle\Realtime\RedisEventPublisher;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Shared "is the thing actually running" checks for the depot home page and
 * StatusController -- pulled out so both read the same signal instead of two
 * copies of the systemctl/pgrep dance drifting apart. Built directly for the
 * recurring failure mode described in docs/scan-jobs-storystation.md: the
 * worker or ai-tools silently isn't running and nothing on screen says so
 * until a scan sits stuck with no visible cause.
 */
final class DepotHealthService
{
    public function __construct(
        #[Autowire(service: 'ai_tools')] private readonly HttpClientInterface $aiToolsClient,
        #[Autowire(service: 'ssai.hub')] private readonly HttpClientInterface $ssaiHubClient,
        private readonly HttpClientInterface $httpClient,
        private readonly SsaiHubBroadcastList $hubs,
        #[Autowire('%env(default::AI_TOOLS_URL)%')] private readonly ?string $aiToolsUrl,
        #[Autowire('%env(default::SSAI_HUB_TOKEN)%')] private readonly ?string $ssaiHubToken,
        #[Autowire('%env(default::ZEBRA_USB_DEVICE)%')] private readonly ?string $zebraUsbDevice,
        #[Autowire('%env(default::DEPOT_EVENTS_DSN)%')] private readonly ?string $depotEventsDsn,
        private readonly DeviceRepository $deviceRepository,
        private readonly CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Live hardware presence, not "is depot's own process up" -- the two are
     * independent (depot can be running fine while the scanner is asleep,
     * unplugged, or physically moved to a different station). Sent with
     * every heartbeat so a hub can tell "online but no scanner attached"
     * apart from "ready to scan" instead of assuming one implies the other.
     * A DB read, not a live probe -- `scanimage -L`'s full backend
     * enumeration measured ~20s live (epsonds net+usb, hpaio, and airscan,
     * every single time regardless of which one is real), far too slow to
     * run on every ~15s heartbeat. `depot:scan-devices` does that slow probe
     * on its own separate, slower schedule and writes what it finds to the
     * device table (see App\Entity\Device); this just checks whether a
     * matching row is still fresh.
     *
     * @return list<string>
     */
    public function detectedCapabilities(): array
    {
        $capabilities = [];

        if ($this->scannerDetected()) {
            $capabilities[] = 'photo_scanner';
        }
        if ($this->labelPrinterDetected()) {
            $capabilities[] = 'label_printer';
        }

        return $capabilities;
    }

    public function scannerDetected(): bool
    {
        return $this->deviceRepository->hasFreshCapability('photo_scanner', Device::FRESH_TTL_SECONDS);
    }

    private function labelPrinterDetected(): bool
    {
        $device = trim((string) $this->zebraUsbDevice);

        return $device !== '' && file_exists($device);
    }

    /**
     * True if the scan-jobs consumer is running, whichever way it was
     * started: the `depot-scan-worker` systemd unit on an appliance station,
     * or a plain `bin/scan-worker.sh` foreground process on a dev laptop
     * where that unit was never installed.
     */
    public function scanWorkerActive(): bool
    {
        return $this->systemdUnitActive('depot-scan-worker.service')
            || $this->processRunning('messenger:consume scan_jobs');
    }

    /**
     * True if the Symfony Scheduler worker (src/Schedule.php: depot:heartbeat
     * every 15s, depot:scan-devices every 2min) is running, whichever way it
     * was started -- same "systemd unit or bare foreground process" pattern
     * as scanWorkerActive() above. Without this running, nothing fires either
     * recurring command, silently -- the whole point of surfacing it here.
     */
    public function schedulerActive(): bool
    {
        return $this->systemdUnitActive('depot-scheduler.service')
            || $this->processRunning('messenger:consume scheduler_default');
    }

    /**
     * Every device `depot:scan-devices` has ever confirmed, freshest first --
     * the home page's own view into App\Entity\Device, so "is the scanner
     * actually being detected" doesn't require a DB console. Freshness
     * (Device::FRESH_TTL_SECONDS) is left to the caller/template: this
     * returns everything, stale rows included, since a device that recently
     * *stopped* being detected is exactly the kind of thing worth seeing.
     *
     * @return list<Device>
     */
    public function devices(): array
    {
        return $this->deviceRepository->findAllOrdered();
    }

    /**
     * Turns the Redis event bus from a black box into "here's the last
     * thing we actually tried to send and whether it worked" -- reachable
     * is a live PING right now (independent of whether publishing is even
     * configured/enabled), lastPulse is what RedisEventPublisher recorded
     * on its last publish() call (see RedisEventPublisher::PULSE_CACHE_KEY).
     * A depot that's been failing to publish for other reasons (e.g. the
     * device-table bug this was built to diagnose) still shows Redis itself
     * as reachable, so the two failure modes don't get conflated.
     *
     * @return array{configured: bool, reachable: bool, lastPulse: array{at: string, channel: string, type: string, success: bool, error: ?string}|null}
     */
    public function redisStatus(): array
    {
        $dsn = trim((string) $this->depotEventsDsn);

        $lastPulse = null;
        try {
            $item = $this->cache->getItem(RedisEventPublisher::PULSE_CACHE_KEY);
            if ($item->isHit()) {
                $lastPulse = $item->get();
            }
        } catch (\Throwable) {
        }

        return [
            'configured' => $dsn !== '',
            'reachable' => $dsn !== '' && $this->pingRedis($dsn),
            'lastPulse' => $lastPulse,
        ];
    }

    private function pingRedis(string $dsn): bool
    {
        try {
            $redis = RedisAdapter::createConnection($dsn, ['timeout' => 1.0]);

            return $redis instanceof \Redis && $redis->ping() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{configured: bool, url: ?string, reachable: bool} */
    public function aiToolsStatus(): array
    {
        return $this->pingService($this->aiToolsUrl, $this->aiToolsClient, '/status');
    }

    /**
     * Every hub this depot broadcasts to (heartbeat + scan results) -- see
     * SsaiHubBroadcastList. The primary uses the pre-configured `ssai.hub`
     * scoped client (matches every other primary-hub call); extras use the
     * plain client with the same shared token, matching how the broadcast
     * itself is sent.
     *
     * @return list<array{url: string, primary: bool, reachable: bool}>
     */
    public function ssaiHubStatuses(): array
    {
        $primary = $this->hubs->primary();

        return array_map(function (string $url) use ($primary): array {
            $isPrimary = $url === $primary;
            $reachable = $isPrimary
                ? $this->pingUrl($this->ssaiHubClient, '/')
                : $this->pingUrlWithToken($url, '/');

            return ['url' => $url, 'primary' => $isPrimary, 'reachable' => $reachable];
        }, $this->hubs->all());
    }

    /**
     * Confirms the station's own Cloudflare tunnel URL is actually live --
     * shown as a link on the home page rather than auto-redirected to it, so
     * the operator's kiosk browser stays on the fast local page (AGENTS.md:
     * "Do not put WAN latency in the operator loop").
     */
    public function publicUrlReachable(string $url): bool
    {
        try {
            $this->httpClient->request('GET', $url, ['timeout' => 3.0])->getStatusCode();

            return true;
        } catch (TransportExceptionInterface) {
            return false;
        }
    }

    /** @return array{configured: bool, url: ?string, reachable: bool} */
    private function pingService(?string $url, HttpClientInterface $client, string $path): array
    {
        $url = trim((string) $url);
        if ($url === '') {
            return ['configured' => false, 'url' => null, 'reachable' => false];
        }

        return ['configured' => true, 'url' => $url, 'reachable' => $this->pingUrl($client, $path)];
    }

    /**
     * Any HTTP response -- even a 404/401 -- means the process answered;
     * only a transport failure (refused, DNS, TLS) counts as "down".
     */
    private function pingUrl(HttpClientInterface $client, string $path): bool
    {
        try {
            $client->request('GET', $path, ['timeout' => 2.0])->getStatusCode();

            return true;
        } catch (TransportExceptionInterface) {
            return false;
        }
    }

    private function pingUrlWithToken(string $url, string $path): bool
    {
        try {
            $this->httpClient->request('GET', $url . $path, [
                'headers' => ['X-Internal-Token' => (string) $this->ssaiHubToken],
                'timeout' => 2.0,
            ])->getStatusCode();

            return true;
        } catch (TransportExceptionInterface) {
            return false;
        }
    }

    private function systemdUnitActive(string $unit): bool
    {
        try {
            $process = new Process(['systemctl', '--user', 'is-active', $unit]);
            $process->run();

            return trim($process->getOutput()) === 'active';
        } catch (\Throwable) {
            return false;
        }
    }

    private function processRunning(string $pattern): bool
    {
        try {
            $process = new Process(['pgrep', '-f', $pattern]);
            $process->run();

            return $process->isSuccessful() && trim($process->getOutput()) !== '';
        } catch (\Throwable) {
            return false;
        }
    }
}
