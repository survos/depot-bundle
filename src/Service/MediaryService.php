<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class MediaryService
{
    public function __construct(
        private readonly HttpClientInterface $mediary,
    ) {
    }

    /**
     * Registers a batch of already-hosted image URLs with mediary. Each front/back
     * pair shares one recordKey — mediary's closest existing analog to an accession —
     * so both sides attach to the same MediaRecord downstream.
     *
     * @param list<array{front: string, back: string, accession: string}> $pairs
     *
     * @return array<string, mixed> mediary's batch response
     */
    public function ensureScanAssets(string $client, string $intakeCode, array $pairs, bool $sync = true): array
    {
        $urls = [];
        $context = [];

        foreach ($pairs as $pair) {
            foreach (['front', 'back'] as $side) {
                $url = $pair[$side];
                $urls[] = $url;
                $context[$url] = [
                    'dataset'   => $intakeCode,
                    'recordKey' => $pair['accession'],
                    'side'      => $side,
                ];
            }
        }

        $response = $this->mediary->request('POST', sprintf('/%s/batch', $client), [
            'json' => [
                'client'   => $client,
                'urls'     => $urls,
                'dispatch' => true,
                'sync'     => $sync,
                'context'  => $context,
            ],
        ]);

        $data = $response->toArray();
        if (!\is_array($data)) {
            throw new \RuntimeException('Unexpected response from mediary batch endpoint.');
        }

        return $data;
    }
}
