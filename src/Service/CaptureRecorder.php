<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Service;

use App\Entity\Capture;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Writes the station's own index row for a scanned pair.
 *
 * Shared deliberately. This logic first landed inside ScanJobRunner only, which
 * covers scans dispatched from ssai -- and left depot:scan:trigger, the manual
 * path, writing nothing. The result was a station whose search showed six
 * browser captures from July while everything actually fed through its scanner
 * was invisible on the machine that scanned it: the exact bug the original fix
 * set out to solve, still live on the other half of the fork. One recorder both
 * paths call is what stops that happening a third time.
 *
 * Wrapped whole in a catch: an appliance that cannot write its own index row
 * must still finish the scan it is in the middle of. The images and the hub
 * hand-off are the product; this row is how the station finds them later.
 */
final readonly class CaptureRecorder
{
    public function __construct(
        private EntityManagerInterface $em,
        private DepotIdentity $identity,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array{front: string, back: string} $pair
     * @param array{left:int,top:int,width:int,height:int}|null $cropRect
     */
    public function record(string $tenant, string $intakeCode, string $accessionLabel, int $sequence, array $pair, string $source, ?array $cropRect = null, int $sidesPerItem = 2): void
    {
        try {
            // A one-role profile has no home for the reverse. The duplex ADF emits
            // it anyway, so it is deleted here rather than indexed: keeping it made
            // the station's search show fifteen photographs as thirty rows, half of
            // them blank card backs, and ssai marked every one `ignored` on arrival.
            //
            // Deleted, not just skipped. A file nobody indexed is worse than one
            // nobody kept -- it sits on the station's disk forever with no row
            // pointing at it, and the operator has no way to find or clear it. The
            // profile said one side; the honest thing is to leave one side.
            $sides = ['front' => $sequence, 'back' => $sequence + 1];
            if ($sidesPerItem === 1) {
                $this->discard($pair['back'] ?? null);
                unset($sides['back']);
            }

            foreach ($sides as $side => $sideSequence) {
                $path = $pair[$side] ?? null;
                if (!\is_string($path) || !is_file($path)) {
                    continue;
                }

                $capture = new Capture(
                    tenantId: $tenant,
                    stationId: $this->identity->label(),
                    filename: basename($path),
                    mimeType: mime_content_type($path) ?: 'image/jpeg',
                    sizeBytes: (int) filesize($path),
                    localPath: $path,
                    publicPath: null,
                    metadata: [
                        // Which path produced this, so a station's own search can
                        // tell an ssai-dispatched job from a manual trigger.
                        'source' => $source,
                        'intakeCode' => $intakeCode,
                        'accession' => $accessionLabel,
                        'sequence' => $sideSequence,
                        'side' => $side,
                        // The rect ai-tools computed for this pair, so the station's
                        // own search can render the PHOTO rather than the whole bed.
                        // Without it the search thumbnail was the raw scan -- a print
                        // in the top half and white scanner bed below, which is what
                        // it looked like: terrible. ssai has always applied this to
                        // its own URLs; depot simply never kept it.
                        'cropRect' => $cropRect,
                    ],
                    intakeCode: $intakeCode,
                    side: $side,
                );

                $this->em->persist($capture);
            }

            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->warning('could not record local capture rows: {err}', ['err' => $e->getMessage()]);
        }
    }

    /**
     * Remove a scan the profile has no role for. Failure is logged, never thrown:
     * a leftover file is untidy, a broken batch is not.
     */
    private function discard(?string $path): void
    {
        if (!\is_string($path) || !is_file($path)) {
            return;
        }

        if (!@unlink($path)) {
            $this->logger->warning('could not delete unused reverse scan {path}', ['path' => $path]);
        }
    }
}
