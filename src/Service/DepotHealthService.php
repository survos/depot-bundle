<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Service;

use App\Entity\Device;
use App\Repository\DeviceRepository;
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
        private readonly DeviceRepository $deviceRepository,
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
