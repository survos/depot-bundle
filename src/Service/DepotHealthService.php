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
use Survos\DepotBundle\Protocol\DepotProtocol;
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
    private const AI_TOOLS_HEALTH_CACHE_KEY = 'depot.ai_tools_reachable';
    private const LAST_PROBE_CACHE_KEY = 'depot.devices_last_probed_at';

    /**
     * Models with a sheet-fed ADF, i.e. the ones a front/back intake profile can
     * actually run in a single duplex pass.
     *
     * The distinction matters because 'photo_scanner' is satisfied by any scanner
     * depot can drive, including the networked ET-3700 flatbed. A station with only
     * that reachable reports a scanner and still cannot run the duplex profiles the
     * intake workflow is built around, so "a scanner exists" and "the work can
     * proceed" are genuinely different questions and are answered separately.
     */
    private const SHEET_FED_MODELS = ['FF-680W'];

    public function __construct(
        #[Autowire(service: 'ai_tools')] private readonly HttpClientInterface $aiToolsClient,
        #[Autowire(service: 'ssai.hub')] private readonly HttpClientInterface $ssaiHubClient,
        private readonly HttpClientInterface $httpClient,
        private readonly SsaiHubBroadcastList $hubs,
        #[Autowire('%env(default::AI_TOOLS_URL)%')] private readonly ?string $aiToolsUrl,
        #[Autowire('%env(default::SSAI_HUB_TOKEN)%')] private readonly ?string $ssaiHubToken,
        #[Autowire('%env(default::AI_TOOLS_SHARED_DIR)%')] private readonly ?string $sharedDir,
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

    /**
     * A sheet-fed ADF scanner is present, not merely some scanner.
     *
     * scannerDetected() above answers the capability question and is what the
     * heartbeat advertises; this answers the operational one -- whether the duplex
     * front/back profiles can run at all. See SHEET_FED_MODELS.
     */
    public function feederDetected(): bool
    {
        return $this->freshSheetFedDevice() !== null;
    }

    private function freshSheetFedDevice(): ?Device
    {
        foreach ($this->deviceRepository->freshDevices(Device::FRESH_TTL_SECONDS) as $device) {
            foreach (self::SHEET_FED_MODELS as $needle) {
                if (str_contains($device->model, $needle)) {
                    return $device;
                }
            }
        }

        return null;
    }

    /**
     * Everything a status panel needs to tell the truth about the hardware, in one
     * read: which devices are currently present, whether one of them can feed
     * sheets, and when a probe last actually ran.
     *
     * That last field is recorded by the probe itself (recordProbeRun()) rather than
     * derived from the freshest device row, because a probe that finds nothing
     * writes no row at all. Timestamping the *read* instead -- which is what this
     * used to do -- reports a live reading whenever anyone loads the page, including
     * when the probe has been dead for hours.
     *
     * @return array{detected: bool, feeder: bool, probedAt: ?string, devices: list<array<string, mixed>>}
     */
    public function scannerSnapshot(): array
    {
        $fresh = $this->deviceRepository->freshDevices(Device::FRESH_TTL_SECONDS);
        $sheetFed = $this->freshSheetFedDevice();

        $devices = [];
        foreach ($fresh as $device) {
            $devices[] = [
                'device' => $device->device,
                'model' => $device->model,
                'capability' => $device->capability,
                'sheetFed' => $sheetFed !== null && $device->device === $sheetFed->device,
                'detectedAt' => $device->detectedAt->format(\DateTimeInterface::ATOM),
            ];
        }

        return [
            'detected' => $this->scannerDetected(),
            'feeder' => $sheetFed !== null,
            'probedAt' => $this->lastProbeAt()?->format(\DateTimeInterface::ATOM),
            'devices' => $devices,
        ];
    }

    /** Called by depot:scan-devices once a probe has actually completed. */
    public function recordProbeRun(): void
    {
        $item = $this->cache->getItem(self::LAST_PROBE_CACHE_KEY);
        $item->set((new \DateTimeImmutable())->format(\DateTimeInterface::ATOM));
        $this->cache->save($item);
    }

    public function lastProbeAt(): ?\DateTimeImmutable
    {
        $item = $this->cache->getItem(self::LAST_PROBE_CACHE_KEY);
        if (!$item->isHit() || !\is_string($value = $item->get())) {
            return null;
        }

        return new \DateTimeImmutable($value);
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
     * What depot and ai-tools each believe about the constants they share.
     *
     * Worth showing on the status page because the failure it catches is
     * invisible otherwise: ai-tools can be running, answering 200, and reported
     * reachable in the heartbeat while pointed at a storage root that is empty,
     * wrong, or non-existent -- so scans land uncropped with every signal green.
     * A station that disagrees with itself should say so on its own front page.
     *
     * @return array{
     *     sharedDir: ?string,
     *     sharedDirExists: bool,
     *     protocolVersion: int,
     *     aiToolsSharedDir: ?string,
     *     aiToolsProtocolVersion: ?int,
     *     inSync: ?bool
     * }
     */
    public function constantsStatus(): array
    {
        $sharedDir = trim((string) $this->sharedDir) ?: null;

        $remoteDir = null;
        $remoteVersion = null;
        try {
            $body = $this->aiToolsClient->request('GET', '/status', ['timeout' => 2.0])->toArray(false);
            // ai-tools has always called this shared_image_dir; matching its
            // existing key rather than inventing a second name for one value.
            $remoteDir = \is_string($body['shared_image_dir'] ?? null) ? $body['shared_image_dir'] : null;
            $remoteVersion = \is_int($body['protocol_version'] ?? null) ? $body['protocol_version'] : null;
        } catch (\Throwable) {
            // Unreachable ai-tools is already reported by aiToolsStatus(); here it
            // just means "cannot compare", which is not the same as "mismatch".
        }

        $inSync = null;
        if ($sharedDir !== null && $remoteDir !== null) {
            $inSync = rtrim($sharedDir, '/') === rtrim($remoteDir, '/')
                && (null === $remoteVersion || $remoteVersion === DepotProtocol::VERSION);
        }

        return [
            'sharedDir' => $sharedDir,
            'sharedDirExists' => $sharedDir !== null && is_dir($sharedDir),
            'protocolVersion' => DepotProtocol::VERSION,
            'aiToolsSharedDir' => $remoteDir,
            'aiToolsProtocolVersion' => $remoteVersion,
            'inSync' => $inSync,
        ];
    }

    /**
     * Live probe, same "write slow, read fast" split as scannerDetected()/
     * detectedCapabilities() above -- but cached (cache.app) instead of a DB
     * row, since ai-tools reachability isn't physical hardware worth an
     * audit trail, just a point-in-time fact the fast heartbeat path needs
     * to read cheaply. Called from depot:scan-devices (the slow, ~20s
     * cadence) so the ~2s HTTP ping never sits on the 15s heartbeat path;
     * aiToolsReachable() below is what heartbeat() actually reads.
     */
    public function refreshAiToolsHealth(): bool
    {
        $reachable = $this->aiToolsStatus()['reachable'];

        $item = $this->cache->getItem(self::AI_TOOLS_HEALTH_CACHE_KEY);
        $item->set($reachable);
        $item->expiresAfter(Device::FRESH_TTL_SECONDS);
        $this->cache->save($item);

        return $reachable;
    }

    /**
     * Cached read only -- never probes live. False (not "unknown") when the
     * cache is empty or has expired: depot:scan-devices hasn't confirmed
     * ai-tools reachable within Device::FRESH_TTL_SECONDS, so treat it the
     * same as "not reachable" rather than silently reporting stale-but-true.
     */
    public function aiToolsReachable(): bool
    {
        $item = $this->cache->getItem(self::AI_TOOLS_HEALTH_CACHE_KEY);

        return $item->isHit() && $item->get() === true;
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
