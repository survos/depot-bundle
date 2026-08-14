<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Controller;

use Survos\DepotBundle\Util\ScanPaths;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * StoryStation's half of the push->pull refactor (see docs/scan-jobs-
 * storystation.md, "Incoming: ssai moving to pull, not push"): lets ssai
 * fetch a scanned+cropped file on its own schedule instead of depot
 * streaming it inline during ingestPair(), which is what made the
 * synchronous upload the suspected cause of #5's idle-timeout hang.
 *
 * StoryStation files aren't Capture entities (no id, no DB row — just
 * files under ScanPaths::outputDir() keyed by intake code + filename), so
 * this is a dedicated route rather than reusing /captures/{id}/file. Token-
 * gated from the start — the existing /captures/{id}/file equivalent for
 * the browser-upload path was flagged as completely unauthenticated, which
 * is fine until something external actually pulls through it; this route
 * is built to be pulled through the public tunnel from day one.
 */
final class ScanFileController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(DEPOT_INBOUND_TOKEN)%')] private readonly string $expectedToken,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(FILES_DATA_DIR)%')] private readonly string $filesDataDir,
    ) {
    }

    #[Route(
        '/internal/scan-files/{intakeCode}/{filename}',
        name: 'internal_scan_file',
        requirements: ['intakeCode' => '[\w.-]+', 'filename' => '[\w.-]+'],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $intakeCode, string $filename): Response
    {
        if ($this->expectedToken === '' || $request->headers->get('X-Internal-Token') !== $this->expectedToken) {
            return new Response('unauthorized', 401);
        }

        // The route requirements above already restrict both segments to
        // \w/./- (no slashes), but outputDir()'s realpath check is the real
        // guard against escaping the intake's own scan directory.
        $path = ScanPaths::outputDir($this->projectDir, $this->filesDataDir, $intakeCode) . '/' . $filename;
        $real = realpath($path);
        $baseReal = realpath(ScanPaths::outputDir($this->projectDir, $this->filesDataDir, $intakeCode));
        if ($real === false || $baseReal === false || !str_starts_with($real, $baseReal) || !is_file($real)) {
            throw $this->createNotFoundException(sprintf('Scan file not found: %s/%s', $intakeCode, $filename));
        }

        $response = new BinaryFileResponse($real);
        // Depot's page-%03d.jpg filenames are reused across every
        // scanDuplexBatch() call within a job, so the SAME URL
        // (.../cheztac-0014/page-001.jpg) legitimately refers to different
        // bytes over time. Cloudflare's default edge caching for
        // image/jpeg content otherwise serves a stale cached response --
        // confirmed live 2026-08-02: ssai kept receiving an old (often
        // uncropped, sometimes from an entirely different scan) file
        // through the public tunnel despite depot's own disk having the
        // correct current bytes (cf-cache-status: HIT, cache-control:
        // public, max-age=14400 on the response). Must not be cached
        // anywhere between depot and ssai.
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
