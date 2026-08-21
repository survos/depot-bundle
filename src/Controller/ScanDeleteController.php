<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Controller;

use Survos\DepotBundle\Util\ScanPaths;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Deletes an intake's scanned page files on disk -- the depot-side half of
 * ssai's "Clear All" (IntakeController::clearAll()): that wipes ssai's
 * Image rows but has no way to touch depot's own var/data/.../scans/{code}
 * directory, so a purge-and-rescan left depot's page-%03d.jpg numbering
 * (glob-based in ScanService::scanDuplexBatch()) still counting up from
 * whatever was there before. Same token gate as ScanFileController, since
 * this is destructive and reachable through the public tunnel.
 */
final class ScanDeleteController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(DEPOT_INBOUND_TOKEN)%')] private readonly string $expectedToken,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
        #[Autowire('%env(FILES_DATA_DIR)%')] private readonly string $filesDataDir,
    ) {
    }

    #[Route(
        '/internal/scan-files/{intakeCode}',
        name: 'internal_scan_files_delete',
        requirements: ['intakeCode' => '[\w.-]+'],
        methods: ['DELETE'],
    )]
    public function __invoke(Request $request, string $intakeCode): JsonResponse
    {
        if ($this->expectedToken === '' || $request->headers->get('X-Internal-Token') !== $this->expectedToken) {
            return new JsonResponse(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $dir = ScanPaths::outputDir($this->projectDir, $this->filesDataDir, $intakeCode);

        if (!is_dir($dir)) {
            return new JsonResponse(['ok' => true, 'deleted' => 0]);
        }

        // Every page file, whatever format the station was set to when it wrote
        // them (see ScanService::pageFiles()), plus scanimage's *.part temporaries
        // -- a partial left behind by an interrupted batch survived the old
        // page-*.jpg glob and then sat in the directory forever, invisible to both
        // this purge and to batch numbering. Found live 2026-08-20 purging
        // cheztac-0002: 15 pages deleted, page-016.jpg.part left behind.
        $files = glob(rtrim($dir, '/') . '/page-*.{jpg,png,tif,pnm,pdf}', \GLOB_BRACE) ?: [];
        $files = array_merge($files, glob(rtrim($dir, '/') . '/page-*.part') ?: []);
        (new Filesystem())->remove($files);

        return new JsonResponse(['ok' => true, 'deleted' => \count($files)]);
    }
}
