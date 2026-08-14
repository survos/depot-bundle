<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SsaiScanHubService
{
    public function __construct(
        private readonly HttpClientInterface $ssaiHub,
        private readonly HttpClientInterface $httpClient,
        private readonly SsaiHubBroadcastList $hubs,
        private readonly DepotIdentity $identity,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(default::SSAI_HUB_TOKEN)%')] private readonly ?string $token,
    ) {
    }

    /**
     * Registers a front/back scan pair with ssai's /internal/scans endpoint.
     * Depot owns acquisition; ssai owns Intake/Image and downstream
     * enrichment — this is the bridge between the two, replacing the old
     * assumption that ssai's own process has a scanner physically attached.
     *
     * Sends metadata + each file's basename, not the file bytes — ssai
     * pulls the actual image from ScanFileController on its own schedule.
     * Was a synchronous multipart upload; that's the suspected cause of
     * #5's idle-timeout hang (a large body sitting in-flight over the
     * tunnel for however long ssai's request handling takes), and it tied
     * scan throughput to upload bandwidth for no reason — depot already
     * has the files on local disk regardless.
     *
     * $analysis (optional): ai-tools' /analyze/autocrop-pair "analysis" key
     * (OCR text/confidence, face count, type triage for front+back) —
     * computed locally, alongside the crop call depot already makes, while
     * the image is still on this machine's own disk. Sent as a JSON string
     * field so ssai can store/parse it without depot needing to know its
     * internal shape.
     *
     * Raw only -- no crop/analysis wait. ai-tools' crop+OCR call measured
     * ~2.7s live, the actual dominant cost in this whole pipeline; the raw
     * scan lands here immediately, and reportCrop() carries the crop
     * rectangle + analysis separately once ai-tools finishes (see that
     * method, and ScanJobRunner which sequences the two calls).
     *
     * The primary hub (SSAI_HUB_URL) gets this call for real — its response
     * is what the caller (ScanJobRunner) depends on, and a failure there
     * still fails the job like before. Any extra hubs in
     * SSAI_HUB_BROADCAST_URLS also get the same pair, best-effort: a hub
     * that doesn't recognize the tenant/intake just declines it (ssai
     * returns 404 for an unknown tenant), which is fine -- "the ssai's can
     * ignore what they don't want."
     *
     * @return array{ok: bool, intake: string, front: array, back: array}
     */
    public function ingestPair(
        string $tenantId,
        string $intakeCode,
        string $accessionHint,
        int $frontSequence,
        string $frontPath,
        string $backPath,
    ): array {
        if (!is_file($frontPath) || !is_file($backPath)) {
            throw new \RuntimeException(sprintf('Cannot register scan files: %s, %s', $frontPath, $backPath));
        }

        // Depot already has the file on local disk -- getimagesize() here is
        // an instant local call, not a network fetch. Reporting dimensions
        // this way means ssai never needs to download anything just to know
        // how big the image is: it can create the Image row and show a
        // thumbnail (via this depot's own imgproxy, see Depot::fastThumbnailUrl())
        // immediately, without touching the bytes at all.
        [$frontWidth, $frontHeight] = self::dimensions($frontPath);
        [$backWidth, $backHeight] = self::dimensions($backPath);

        $json = [
            'tenantId'       => $tenantId,
            'intakeCode'     => $intakeCode,
            'accessionHint'  => $accessionHint,
            'frontSequence'  => $frontSequence,
            'frontFilename'  => basename($frontPath),
            'backFilename'   => basename($backPath),
            'frontWidth'     => $frontWidth,
            'frontHeight'    => $frontHeight,
            'backWidth'      => $backWidth,
            'backHeight'     => $backHeight,
            // Lets ssai pull the bytes from THIS depot (via the Depot
            // registry) instead of falling back to Tenant::$edgeUrl's stale
            // naming convention -- the tenant's "default" depot and the one
            // that actually scanned this pair aren't necessarily the same
            // once more than one depot exists.
            'depotLabel'     => $this->identity->label(),
        ];

        $response = $this->ssaiHub->request('POST', '/internal/scans', ['json' => $json]);
        $data = $response->toArray();

        if (!\is_array($data)) {
            throw new \RuntimeException('Unexpected response from ssai scan-ingest endpoint.');
        }

        $this->broadcastToExtraHubs('/internal/scans', $json);

        return $data;
    }

    /**
     * Depot's second call for a pair, once ai-tools' crop/deskew+OCR call
     * finishes -- $rect is ai-tools' own {center: [x,y], size: [w,h], angle}
     * response shape (autocropPair()), converted here to the {left, top,
     * width, height} ssai's Depot::fastThumbnailUrl() expects. No file
     * involved either way.
     *
     * Best-effort and non-fatal by design (unlike ingestPair()): the raw
     * scan already landed, so a failed report here just means this pair
     * stays uncropped rather than the whole job stopping -- see
     * ScanJobRunner, which surfaces $cropError instead of failing on it
     * (a past incident let uncropped images through silently when this
     * WAS treated as fatal; visible-but-non-fatal is the fix, not "ignore
     * it"). "Best-effort" still means a few retries against the primary
     * hub first though, not a single try -- this call has no durable queue
     * behind it (see the "for now it's just POSTs" scope), so a transient
     * blip here is otherwise a silent, permanent loss: the image would sit
     * at "full page, cropping..." forever with nothing to retry it.
     *
     * @param array{center: array{0: float, 1: float}, size: array{0: float, 1: float}, angle: float}|null $rect
     * @param array<string, mixed>|null $analysis ai-tools' analysis key, keyed 'front'/'back'
     */
    public function reportCrop(
        string $tenantId,
        string $intakeCode,
        int $frontSequence,
        ?array $rect,
        ?string $cropError,
        ?array $analysis,
    ): void {
        $box = $rect !== null ? self::rectToBox($rect) : null;

        $json = [
            'tenantId'       => $tenantId,
            'intakeCode'     => $intakeCode,
            'frontSequence'  => $frontSequence,
            'frontCropRect'  => $box,
            'backCropRect'   => $box, // same detected boundary reused for both sides, matching autocropPair()'s own pairing
            'frontCropError' => $cropError,
            'backCropError'  => $cropError,
            'frontAnalysis'  => $analysis['front'] ?? null,
            'backAnalysis'   => $analysis['back'] ?? null,
        ];

        $maxAttempts = 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                $this->ssaiHub->request('POST', '/internal/scans/crop', ['json' => $json]);
                break;
            } catch (ExceptionInterface $e) {
                if ($attempt < $maxAttempts) {
                    $this->logger->debug('reportCrop failed against primary hub, retrying', ['attempt' => $attempt, 'error' => $e->getMessage()]);
                    sleep(1);
                    continue;
                }
                $this->logger->warning('reportCrop failed against primary hub after retries, giving up', ['attempts' => $maxAttempts, 'error' => $e->getMessage()]);
            }
        }

        $this->broadcastToExtraHubs('/internal/scans/crop', $json);
    }

    /** @param array{center: array{0: float, 1: float}, size: array{0: float, 1: float}, angle: float} $rect */
    private static function rectToBox(array $rect): array
    {
        [$cx, $cy] = $rect['center'];
        [$w, $h] = $rect['size'];

        return [
            'left'   => (int) round($cx - $w / 2),
            'top'    => (int) round($cy - $h / 2),
            'width'  => (int) round($w),
            'height' => (int) round($h),
        ];
    }

    /** @return array{0: int, 1: int} */
    private static function dimensions(string $path): array
    {
        $size = @getimagesize($path);

        return [$size[0] ?? 0, $size[1] ?? 0];
    }

    /** @param array<string, mixed> $json */
    private function broadcastToExtraHubs(string $path, array $json): void
    {
        $primary = $this->hubs->primary();

        foreach ($this->hubs->all() as $hub) {
            if ($hub === $primary) {
                continue; // already sent above via the scoped ssai.hub client
            }

            try {
                $this->httpClient->request('POST', $hub . $path, [
                    'json'    => $json,
                    'headers' => ['X-Internal-Token' => (string) $this->token],
                    'timeout' => 5.0,
                ]);
            } catch (ExceptionInterface $e) {
                $this->logger->debug('broadcast failed, ignoring', ['hub' => $hub, 'path' => $path, 'error' => $e->getMessage()]);
            }
        }
    }
}
