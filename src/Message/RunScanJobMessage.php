<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Message;

final class RunScanJobMessage
{
    public function __construct(
        public readonly int $jobId,
        public readonly string $tenantId,
        public readonly string $intakeCode,
        public readonly ?string $startingLabel,
        /**
         * The intake profile's image-role count, from ssai. 1 means the reverse
         * has no role and must not be kept: the duplex ADF emits it regardless,
         * and every layer downstream used to accept it -- forwarded to the hub,
         * indexed on the station, then marked `ignored` by ssai. Defaults to 2
         * so an older hub that does not send it behaves exactly as before.
         */
        public readonly int $sidesPerItem = 2,
        /**
         * The capture area the operator selected, in millimetres. Null means they did
         * not say -- a mixed stack, or no size picked -- and ScanService falls back to
         * its own station default.
         */
        public readonly ?int $feederWidthMm = null,
        public readonly ?int $feederHeightMm = null,
    ) {
    }
}
