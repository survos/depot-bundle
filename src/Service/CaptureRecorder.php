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
     */
    public function record(string $tenant, string $intakeCode, string $accessionLabel, int $sequence, array $pair, string $source): void
    {
        try {
            foreach (['front' => $sequence, 'back' => $sequence + 1] as $side => $sideSequence) {
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
}
