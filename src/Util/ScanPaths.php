<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Util;

/**
 * FILES_DATA_DIR is relative in dev (public/uploads, joined under the project
 * dir) but absolute on an appliance (an external mount like /media/.../WD-001)
 * -- joining it under projectDir unconditionally silently produced a bogus
 * nested path when it was absolute (e.g. depot/media/.../WD-001/scans/...).
 */
final class ScanPaths
{
    public static function outputDir(string $projectDir, string $filesDataDir, string $intakeCode): string
    {
        $base = str_starts_with($filesDataDir, '/')
            ? rtrim($filesDataDir, '/')
            : rtrim($projectDir, '/') . '/' . trim($filesDataDir, '/');

        return $base . '/scans/' . $intakeCode;
    }
}
