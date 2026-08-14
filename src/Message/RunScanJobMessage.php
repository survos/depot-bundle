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
    ) {
    }
}
