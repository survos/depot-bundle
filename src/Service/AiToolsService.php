<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AiToolsService
{
    public function __construct(
        private readonly HttpClientInterface $aiTools,
    ) {
    }

    /**
     * Detects the crop/deskew rectangle for a front/back scan pair via
     * ai-tools' /analyze/autocrop-pair (background-color-distance detection
     * — see ai-tools/cv/photo_scan.py). Pair-aware: the front's detected
     * boundary is reused for the back, since a photo's back is mostly blank
     * and its own content-based detection would clip real handwriting.
     *
     * Rect-only, not crop-and-write: front_out/back_out point at throwaway
     * temp paths (cleaned up here), not the real scan files -- the crop
     * itself is never baked into a second file. Depot's imgproxy renders it
     * on demand against the ORIGINAL, which must stay untouched on disk for
     * that to work (see ssai's Depot::fastThumbnailUrl()).
     *
     * Also runs OCR/face-count/type triage on both sides (same call, no
     * extra round-trip) — response includes an "analysis" key:
     * {front: {type, document_likely, photo_likely, photo_with_text_likely,
     * face_count, ocr_text, ocr_confidence, ocr_word_count}, back: {...}}.
     * Computed while the image is local and high-res; pass
     * run_analysis=false in the request if a caller ever needs to skip it.
     *
     * @return array{ok: bool, reason?: string, rect?: array{center: array{0: float, 1: float}, size: array{0: float, 1: float}, angle: float}, analysis?: array}&array<string, mixed>
     */
    public function autocropPair(string $frontPath, string $backPath): array
    {
        // tempnam() itself creates an empty file at an extensionless path --
        // ai-tools writes via OpenCV's cv2.imwrite(), which picks the output
        // format from the path's extension and errors ("could not find a
        // writer for the specified extension") without one. Found live:
        // every crop call 500'd from the moment this switched to throwaway
        // temp paths. Keep the original tempnam() path around too, purely
        // so its empty file gets cleaned up -- ai-tools writes to the
        // .jpg-suffixed sibling instead, which tempnam() never created.
        $frontTemp = tempnam(sys_get_temp_dir(), 'ai-tools-crop-');
        $backTemp = tempnam(sys_get_temp_dir(), 'ai-tools-crop-');
        $frontOut = $frontTemp . '.jpg';
        $backOut = $backTemp . '.jpg';

        try {
            $response = $this->aiTools->request('POST', '/analyze/autocrop-pair', [
                'json' => [
                    'front_path' => $frontPath,
                    'back_path'  => $backPath,
                    'front_out'  => $frontOut,
                    'back_out'   => $backOut,
                ],
            ]);

            $data = $response->toArray();
            if (!\is_array($data)) {
                throw new \RuntimeException('Unexpected response from ai-tools autocrop-pair endpoint.');
            }

            return $data;
        } finally {
            foreach ([$frontTemp, $backTemp, $frontOut, $backOut] as $path) {
                if ($path !== false) {
                    @unlink($path);
                }
            }
        }
    }
}
