<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Depot's own record of "what am I actually doing right now" -- the thing
 * that was missing when a real ScanJob sat at pairs_scanned=0 with no way
 * to tell, from outside, whether the consumer picked it up, a scan was in
 * progress, or something failed silently before ever calling fail() (see
 * the GitHub issue this was built for).
 *
 * A plain JSON file, not a cache pool or a new entity: this is exactly the
 * kind of fact an operator SSHed into a headless appliance wants to be able
 * to `cat` directly, and it needs to survive independent of any cache
 * pool's own clear/TTL semantics.
 */
final class ScanJobStatusStore
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    /**
     * @param array<string, mixed> $fields
     */
    public function update(array $fields): void
    {
        $current = $this->read();
        $merged = array_merge($current, $fields, [
            'lastActivityAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ]);
        $this->write($merged);
    }

    public function recordSuccessfulScan(): void
    {
        $current = $this->read();
        $current['lastSuccessfulScanAt'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $this->write($current);
    }

    /**
     * @return array<string, mixed>
     */
    public function read(): array
    {
        if (!is_file($this->path())) {
            return [];
        }

        $data = json_decode((string) file_get_contents($this->path()), true);

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(array $data): void
    {
        $path = $this->path();
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, json_encode($data, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR), \LOCK_EX);
    }

    private function path(): string
    {
        return rtrim($this->projectDir, '/') . '/var/scan-job-status.json';
    }
}
