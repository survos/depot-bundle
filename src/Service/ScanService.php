<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

final class ScanService
{
    public function __construct(
        #[Autowire('%env(SCANIMAGE_DEVICE)%')] private readonly string $configuredDevice = '',
        #[Autowire('%env(SCANIMAGE_RESOLUTION)%')] private readonly string $resolution = '300',
        #[Autowire('%env(SCANIMAGE_MODE)%')] private readonly string $mode = 'Color',
        #[Autowire('%env(SCANIMAGE_SOURCE)%')] private readonly string $source = 'ADF Duplex',
    ) {
    }

    /**
     * Runs a duplex batch scan and returns the ordered output files, paired front/back.
     *
     * @return list<array{front: string, back: string}>
     */
    public function scanDuplexBatch(string $outputDir): array
    {
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new \RuntimeException(sprintf('Failed to create scan output directory: %s', $outputDir));
        }

        $device = $this->configuredDevice !== '' ? $this->configuredDevice : $this->discoverFastFotoDevice();
        $pattern = rtrim($outputDir, '/') . '/page-%03d.jpg';

        // scanimage's own batch numbering always restarts at 1 unless told
        // otherwise -- an intake normally spans several reload-the-feeder
        // batches (ScanJobRunner's whole loop exists for that), so without
        // --batch-start every batch after the first would silently
        // overwrite the previous one's page-001.jpg etc. Found live: this
        // broke depot's fast-thumbnail path, which reads these files
        // straight off disk on demand (see ssai's Depot::fastThumbnailUrl())
        // rather than copying bytes elsewhere the instant they're scanned --
        // an old Image's thumbnail would start silently showing a newer,
        // unrelated photo the moment its filename got reused.
        $batchStart = $this->nextBatchStart($outputDir);

        // scanimage exits 0 when the batch ends after scanning at least one page
        // (the normal end-of-stack condition), but exits 7 ("Document feeder out
        // of documents") if the feeder was empty from the very first attempt —
        // both are "no paper loaded", just surfaced differently.
        try {
            (new Process([
                'scanimage',
                '-d', $device,
                '--source', $this->source,
                '--mode', $this->mode,
                '--resolution', $this->resolution,
                '--format=jpeg',
                '--batch=' . $pattern,
                '--batch-start=' . $batchStart,
            ], timeout: 300))->mustRun();
        } catch (ProcessFailedException $e) {
            if (str_contains($e->getProcess()->getErrorOutput(), 'Document feeder out of documents')) {
                throw new \RuntimeException('No paper loaded — load photos in the feeder and try again.');
            }

            throw $e;
        }

        // Only this batch's own files -- glob() alone would also pick up
        // every earlier batch's still-present files now that numbering no
        // longer collides, which would re-pair and re-return photos
        // ScanJobRunner already handed off, under new (wrong) sequence
        // numbers.
        $files = array_values(array_filter(
            glob(rtrim($outputDir, '/') . '/page-*.jpg') ?: [],
            static fn(string $f): bool => self::pageNumber($f) >= $batchStart,
        ));
        sort($files);

        if ($files === []) {
            throw new \RuntimeException('Scan produced no output files — check that paper is loaded in the feeder.');
        }

        return $this->pairFiles($files);
    }

    /**
     * Same scanimage invocation as scanDuplexBatch(), but yields each pair
     * the instant both its files are written, instead of waiting for the
     * WHOLE feeder-load to finish (or error) before returning anything.
     *
     * scanDuplexBatch() ran scanimage via mustRun() -- fully blocking -- then
     * globbed the directory only after the subprocess exited. scanimage's
     * own batch mode writes one page at a time as it physically scans
     * (confirmed live: its progress output streams "Scanning page N /
     * Scanned page N" per page, not all at once at the end), so that
     * approach had two real costs, both found live: (a) nothing appeared on
     * the capture page until the operator's entire stack finished scanning
     * -- dead air for as long as a full feeder load takes -- and (b) a
     * hardware fault partway through (2026-08-13: "sane_start: Error during
     * device I/O" after 10 pages) lost every already-scanned page in that
     * batch, since the exception fired before scanDuplexBatch() ever
     * returned anything at all. Streaming means the caller (ScanJobRunner)
     * can hand each pair to ssai the moment it exists, and keeps whatever
     * was already yielded even if scanimage dies partway through the rest.
     *
     * @return \Generator<array{front: string, back: string}>
     */
    public function scanDuplexBatchStream(string $outputDir): \Generator
    {
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new \RuntimeException(sprintf('Failed to create scan output directory: %s', $outputDir));
        }

        $device = $this->configuredDevice !== '' ? $this->configuredDevice : $this->discoverFastFotoDevice();
        $pattern = rtrim($outputDir, '/') . '/page-%03d.jpg';
        $batchStart = $this->nextBatchStart($outputDir);

        $process = new Process([
            'scanimage',
            '-d', $device,
            '--source', $this->source,
            '--mode', $this->mode,
            '--resolution', $this->resolution,
            '--format=jpeg',
            '--batch=' . $pattern,
            '--batch-start=' . $batchStart,
        ], timeout: 300);
        $process->start();

        $nextExpected = $batchStart;
        $pendingSingle = null;
        $yielded = 0;

        while ($process->isRunning()) {
            usleep(300_000);
            foreach ($this->collectStablePairs($outputDir, $nextExpected, $pendingSingle) as $pair) {
                $yielded++;
                yield $pair;
            }
        }

        // scanimage may have written its last page(s) between the final
        // poll above and the process actually exiting -- one more pass.
        foreach ($this->collectStablePairs($outputDir, $nextExpected, $pendingSingle) as $pair) {
            $yielded++;
            yield $pair;
        }

        if (!$process->isSuccessful()) {
            $errorOutput = $process->getErrorOutput();
            if (str_contains($errorOutput, 'Document feeder out of documents')) {
                if ($yielded === 0) {
                    throw new \RuntimeException('No paper loaded — load photos in the feeder and try again.');
                }

                return; // Normal end-of-stack after at least one real pair -- not an error.
            }

            throw new ProcessFailedException($process);
        }
    }

    /**
     * Advances $nextExpected/$pendingSingle (by reference) past every
     * page-%03d.jpg found in strict sequence starting at $nextExpected,
     * yielding a pair every second file. Stops at the first gap -- either
     * scanimage hasn't written that page yet, or it's mid-write (see
     * isFileStable()) -- so a pair is never yielded from a file still being
     * flushed to disk.
     *
     * @return \Generator<array{front: string, back: string}>
     */
    private function collectStablePairs(string $outputDir, int &$nextExpected, ?string &$pendingSingle): \Generator
    {
        while (true) {
            $file = rtrim($outputDir, '/') . '/' . sprintf('page-%03d.jpg', $nextExpected);
            if (!is_file($file) || !self::isFileStable($file)) {
                return;
            }

            if ($pendingSingle === null) {
                $pendingSingle = $file;
            } else {
                // ADF's first page out of each sheet is the facedown/written
                // side, the second the faceup/photo side -- see pairFiles()'s
                // own docblock for how this was confirmed live.
                yield ['front' => $file, 'back' => $pendingSingle];
                $pendingSingle = null;
            }

            $nextExpected++;
        }
    }

    /** Guards against reading a page-%03d.jpg while scanimage is still writing it. */
    private static function isFileStable(string $path): bool
    {
        $size1 = @filesize($path);
        if ($size1 === false) {
            return false;
        }
        usleep(100_000);
        clearstatcache(true, $path);
        $size2 = @filesize($path);

        return $size2 !== false && $size1 === $size2;
    }

    private function nextBatchStart(string $outputDir): int
    {
        $files = glob(rtrim($outputDir, '/') . '/page-*.jpg') ?: [];
        $max = 0;
        foreach ($files as $file) {
            $max = max($max, self::pageNumber($file));
        }

        return $max + 1;
    }

    private static function pageNumber(string $path): int
    {
        return (int) preg_replace('/\D/', '', basename($path));
    }

    /**
     * Pairs up page-*.jpg files already sitting in $outputDir, without
     * touching the scanner — lets the crop/hand-off pipeline be exercised
     * end-to-end from existing images when no hardware is available.
     *
     * @return list<array{front: string, back: string}>
     */
    public function pairExistingScans(string $outputDir): array
    {
        $files = glob(rtrim($outputDir, '/') . '/page-*.jpg') ?: [];
        sort($files);

        if ($files === []) {
            throw new \RuntimeException(sprintf('No page-*.jpg files found in %s.', $outputDir));
        }

        return $this->pairFiles($files);
    }

    /**
     * @param list<string> $files
     * @return list<array{front: string, back: string}>
     */
    private function pairFiles(array $files): array
    {
        if (\count($files) % 2 !== 0) {
            throw new \RuntimeException(sprintf(
                'Expected an even number of duplex pages, got %d — a page may have jammed or misfed.',
                \count($files),
            ));
        }

        $pairs = [];
        for ($i = 0; $i < \count($files); $i += 2) {
            // Loading postcards per Epson's "Photo mode" convention (faceup,
            // landscape, top edge first -- the only way to get a duplex pass
            // of a photo + its handwritten back at all, per the FF-680W
            // user's guide), the ADF's first page out of each sheet is
            // consistently the facedown/written side and the second is the
            // faceup/photo side -- confirmed live 2026-08-03 by reading the
            // actual scanned files for two different postcards. The naive
            // $files[$i]=front assumption had this backwards for every pair.
            $pairs[] = ['front' => $files[$i + 1], 'back' => $files[$i]];
        }

        return $pairs;
    }

    private function discoverFastFotoDevice(): string
    {
        $process = new Process(['scanimage', '-L']);
        $process->mustRun();

        foreach (explode("\n", $process->getOutput()) as $line) {
            if (str_contains($line, 'FF-680W') && preg_match('/device `([^\x27]+)\x27/', $line, $m) === 1) {
                return $m[1];
            }
        }

        throw new \RuntimeException('No Epson FastFoto FF-680W found via `scanimage -L`; set SCANIMAGE_DEVICE explicitly.');
    }
}
