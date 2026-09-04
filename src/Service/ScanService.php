<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Service;

use Psr\Log\LoggerInterface;
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
        /**
         * scanimage output format. Defaults to jpeg for backward compatibility,
         * but note what that costs: scanimage exposes NO quality knob (only
         * --format), and the Epson writes JPEG at roughly libjpeg q75 --
         * measured 2026-08-20 from the quantization table of a real scan, which
         * was the standard Annex K table halved. On a normally-exposed print
         * that is invisible; in dense shadow it is not. Confirmed live on
         * cheztac-0005 page-009 (a dim bedroom snapshot with a dark framed
         * portrait on the wall): at 1:1 the portrait showed hard 8x8 block
         * edges and magenta/cyan chroma speckle through tones that are smooth
         * on the actual print. Resolution was not the limiting factor -- the
         * quantizer was. Set to png for lossless capture when shadow detail
         * matters more than disk.
         */
        #[Autowire('%env(default::SCANIMAGE_FORMAT)%')] private readonly ?string $formatOverride = null,
        /**
         * Which side of each sheet the ADF emits FIRST. This is not a fixed
         * property of the scanner -- it depends on how the operator loads the
         * feeder, so it has to be a per-station setting rather than a constant.
         *
         * false (default) = written/back side comes out first. That is the
         * documented postcard workflow: faceup, landscape, top edge first (see
         * pairFiles(), confirmed live 2026-08-03).
         *
         * true = photo side comes out first. That is what the FF-680W's own
         * recommended loading (upside down, faces out) produces -- confirmed
         * live 2026-08-20 by reading cheztac-0004's page-001 (a portrait) and
         * page-002 (its handwritten back), which the default mapping had
         * exactly inverted.
         */
        #[Autowire('%env(bool:default::SCAN_DUPLEX_PHOTO_SIDE_FIRST)%')] private readonly bool $photoSideFirst = false,
        /**
         * Feeder width in MILLIMETRES -- the width the ADF guides are set to, i.e. the
         * long edge of the photo, since stock is loaded landscape.
         *
         * Loading landscape is what makes this worth doing: if the long edge is the width,
         * nothing in the stack can be TALLER than the width either, so one number bounds
         * both axes and the acquire area becomes a width x width square. A 6in photo drops
         * the capture from the full 8.5x15.5in bed to 6x6in -- about a quarter of the
         * pixels -- which is less USB transfer (the dominant cost of a scan), a smaller
         * file, and a preview whose 360px thumbnail covers 6in instead of 15.5in.
         *
         * This is a rough first pass on purpose. It never cuts into the photo (the sheet
         * cannot exceed the guides) and it removes the empty bed, which is all margin. Fine
         * cropping to the actual paper edge still happens downstream in ai-tools.
         *
         * Left empty = scan the whole bed, the previous behaviour.
         *
         * Not obtainable from the hardware: the scanner reports no guide position, and its
         * ADF/CRP capability is a -20..+20 margin ADJUSTMENT, not paper detection --
         * confirmed live 2026-08-23, where --adf-crp=yes changed neither the emitted #ACQ
         * (still 0,0-5096x9283) nor the output size. So the operator has to say.
         */
        #[Autowire('%env(default::SCAN_FEEDER_WIDTH_MM)%')] private readonly ?string $feederWidthMm = null,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Acquire-area arguments for scanimage, or [] for the whole bed.
     *
     * Applied at CAPTURE time rather than cropping afterwards: -x/-y change the #ACQ the
     * backend sends, so the scanner never digitises or transfers the empty bed in the
     * first place. Cropping after the fact would cost the transfer anyway.
     *
     * @return list<string>
     */
    /**
     * The ADF source to ask for.
     *
     * Simplex when the intake profile has one image role: the scanner then emits one
     * file per sheet instead of two, so a reverse is never digitised, never transferred
     * and never written. Previously it was always duplex and the unwanted side was
     * dealt with afterwards -- forwarded to the hub, indexed on the station, then marked
     * `ignored` on arrival. Not scanning it is strictly better than three systems
     * agreeing to throw it away.
     *
     * The station's configured source stays the default, so a hub that says nothing
     * behaves exactly as before.
     */
    private function sourceFor(int $sidesPerItem): string
    {
        if ($sidesPerItem !== 1) {
            return $this->source;
        }

        // "ADF Front" is the FF-680W's simplex source. Falls back to whatever the
        // station configured if it is already a single-sided source, so an operator
        // who set this deliberately is not overridden.
        return str_contains(strtolower($this->source), 'duplex') ? 'ADF Front' : $this->source;
    }

    private function acquireArea(?int $widthMm = null, ?int $heightMm = null): array
    {
        $mm = $widthMm !== null && $widthMm > 0
            ? (float) $widthMm
            : (float) trim((string) $this->feederWidthMm);

        if ($mm <= 0) {
            return [];
        }

        // Both edges when the operator named a size, and the capture is the print.
        // Only the width when they did not -- a mixed stack, or no size picked -- and
        // it degrades to the width x width square this used to always be. That square
        // is safe because nothing loaded landscape can be taller than the guides are
        // wide, but it is loose: a 3.5x5 fed landscape is 127 x 89mm, so the square
        // digitises 38mm of bare bed on every sheet, roughly a third of the pixels,
        // transferred over USB and then cropped away downstream.
        $height = $heightMm !== null && $heightMm > 0 ? (float) $heightMm : $mm;

        // Clamped to the bed. -x is limited to 215.9mm and -y to 393.7mm on the FF-680W;
        // asking for more is an error, not a bigger scan.
        $x = min($mm, 215.9);
        $y = min($height, 393.7);

        return ['-x', (string) $x, '-y', (string) $y];
    }

    /** scanimage's --format value. */
    private function format(): string
    {
        $format = strtolower(trim((string) $this->formatOverride));

        return $format !== '' ? $format : 'jpeg';
    }

    /**
     * File extension for the configured format. Kept separate from format()
     * because scanimage takes "jpeg"/"tiff" while the files it writes are
     * .jpg/.tif -- every glob and filename pattern below goes through this so
     * a format change can't leave half the pipeline looking for the old
     * extension.
     */
    private function extension(): string
    {
        return match ($this->format()) {
            'png'  => 'png',
            'tiff' => 'tif',
            'pnm'  => 'pnm',
            'pdf'  => 'pdf',
            default => 'jpg',
        };
    }

    /**
     * Every page file this station could have written, newest format or not.
     * Deliberately NOT just the current extension: switching a station to png
     * must not make the pages it already scanned as .jpg invisible to batch
     * numbering (which would restart at 1 and collide) or to deletion (which
     * would silently leave them behind).
     *
     * @return list<string>
     */
    private function pageFiles(string $outputDir): array
    {
        $files = glob(rtrim($outputDir, '/') . '/page-*.{jpg,png,tif,pnm,pdf}', \GLOB_BRACE) ?: [];
        sort($files);

        return array_values($files);
    }

    /**
     * Maps a sheet's two pages to front/back per $photoSideFirst. Single place
     * both pairing paths go through, so the streaming scan and the offline
     * pairExistingScans() can never disagree about which side is which.
     *
     * @return array{front: string, back: string}
     */
    private function pairSides(string $firstOut, string $secondOut): array
    {
        return $this->photoSideFirst
            ? ['front' => $firstOut, 'back' => $secondOut]
            : ['front' => $secondOut, 'back' => $firstOut];
    }

    /**
     * Runs a duplex batch scan and returns the ordered output files, paired front/back.
     *
     * @return list<array{front: string, back: string}>
     */
    public function scanDuplexBatch(string $outputDir, ?int $widthMm = null, ?int $heightMm = null, int $sidesPerItem = 2): array
    {
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new \RuntimeException(sprintf('Failed to create scan output directory: %s', $outputDir));
        }

        $device = $this->configuredDevice !== '' ? $this->configuredDevice : $this->discoverFastFotoDevice();
        $pattern = rtrim($outputDir, '/') . '/page-%03d.' . $this->extension();

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
                '--source', $this->sourceFor($sidesPerItem),
                '--mode', $this->mode,
                '--resolution', $this->resolution,
                '--format=' . $this->format(),
                ...$this->acquireArea($widthMm, $heightMm),
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
            $this->pageFiles($outputDir),
            static fn(string $f): bool => self::pageNumber($f) >= $batchStart,
        ));
        sort($files);

        if ($files === []) {
            throw new \RuntimeException('Scan produced no output files — check that paper is loaded in the feeder.');
        }

        return $this->pairFiles($files, $sidesPerItem);
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
    public function scanDuplexBatchStream(string $outputDir, ?int $widthMm = null, ?int $heightMm = null, int $sidesPerItem = 2): \Generator
    {
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new \RuntimeException(sprintf('Failed to create scan output directory: %s', $outputDir));
        }

        $device = $this->configuredDevice !== '' ? $this->configuredDevice : $this->discoverFastFotoDevice();
        $pattern = rtrim($outputDir, '/') . '/page-%03d.' . $this->extension();
        $batchStart = $this->nextBatchStart($outputDir);

        $process = new Process([
            'scanimage',
            '-d', $device,
            '--source', $this->sourceFor($sidesPerItem),
            '--mode', $this->mode,
            '--resolution', $this->resolution,
            '--format=' . $this->format(),
            ...$this->acquireArea($widthMm, $heightMm),
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
            $file = rtrim($outputDir, '/') . '/' . sprintf('page-%03d.%s', $nextExpected, $this->extension());
            if (!is_file($file) || !self::isFileStable($file)) {
                return;
            }

            if ($pendingSingle === null) {
                $pendingSingle = $file;
            } else {
                // Which of the two is the photo depends on feeder loading --
                // see pairSides() / $photoSideFirst.
                yield $this->pairSides($pendingSingle, $file);
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
        $files = $this->pageFiles($outputDir);
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
        $files = $this->pageFiles($outputDir);

        if ($files === []) {
            throw new \RuntimeException(sprintf('No page-* image files found in %s.', $outputDir));
        }

        return $this->pairFiles($files, $sidesPerItem);
    }

    /**
     * @param list<string> $files
     * @return list<array{front: string, back: string}>
     */
    private function pairFiles(array $files, int $sidesPerItem = 2): array
    {
        // Simplex: one file per sheet, so there is nothing to pair and an odd count is
        // normal rather than a misfeed. The back is null all the way through, which is
        // what tells the hub this sheet has one side.
        if ($sidesPerItem === 1) {
            return array_map(static fn (string $file): array => ['front' => $file, 'back' => null], $files);
        }

        if (\count($files) % 2 !== 0) {
            throw new \RuntimeException(sprintf(
                'Expected an even number of duplex pages, got %d — a page may have jammed or misfed.',
                \count($files),
            ));
        }

        $pairs = [];
        for ($i = 0; $i < \count($files); $i += 2) {
            // Which side comes out first is a property of how the feeder was
            // loaded, not of the scanner -- see pairSides() / $photoSideFirst.
            $pairs[] = $this->pairSides($files[$i], $files[$i + 1]);
        }

        return $pairs;
    }

    private function discoverFastFotoDevice(): string
    {
        $process = new Process(['scanimage', '-L']);
        $process->mustRun();

        // USB FIRST, then anything else. The FF-680W is also a network scanner, so when it
        // is on wifi `scanimage -L` lists BOTH an epsonds:net: and an epsonds:libusb:
        // entry for the same physical device -- and returning whichever came first meant a
        // 600dpi duplex batch could silently go over wifi. Found live 2026-08-24: a scan
        // ran as epsonds:net:192.168.86.55 and took ~4 MINUTES to produce its first pair
        // (15MB/page at 600dpi), with the ADF stalling after one sheet because the host
        // could not drain fast enough. Over USB the same batch is seconds per pair.
        //
        // Two passes rather than a sort: the preference is absolute, not a ranking, and
        // this way a station with only a network path still works instead of failing.
        $candidates = [];
        foreach (explode("\n", $process->getOutput()) as $line) {
            if (str_contains($line, 'FF-680W') && preg_match('/device `([^\x27]+)\x27/', $line, $m) === 1) {
                $candidates[] = $m[1];
            }
        }

        foreach ($candidates as $device) {
            if (str_contains($device, ':libusb:')) {
                return $device;
            }
        }

        if ($candidates !== []) {
            $this->logger?->warning('No USB path to the FF-680W; falling back to {device}. Expect a much slower scan.', ['device' => $candidates[0]]);

            return $candidates[0];
        }

        throw new \RuntimeException('No Epson FastFoto FF-680W found via `scanimage -L`; set SCANIMAGE_DEVICE explicitly.');
    }
}
