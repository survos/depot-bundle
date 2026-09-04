<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Controller;

use Survos\DepotBundle\Message\RescanDevicesMessage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Sync now" for attached hardware.
 *
 * Capability lives behind a 300s freshness window fed by a 2-minute schedule, which is
 * the right cadence for a machine and the wrong one for a person: an operator plugs in a
 * scanner, or clears a jam, and then watches a disabled Start Scanning button with
 * nothing telling them whether it took. A hub can ask for a probe when someone opens a
 * capture page and have the answer on the next 15s heartbeat.
 *
 * Returns immediately. The probe is ~20s of backend enumeration and must never sit
 * inside a page load -- the point is to start it earlier, not to wait for it.
 */
final class RescanDevicesController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $bus,
        #[Autowire('%env(DEPOT_INBOUND_TOKEN)%')] private readonly string $expectedToken,
    ) {
    }

    #[Route('/internal/devices/rescan', name: 'internal_devices_rescan', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->expectedToken === '' || $request->headers->get('X-Internal-Token') !== $this->expectedToken) {
            return new JsonResponse(['ok' => false, 'reason' => 'unauthorized'], 401);
        }

        $reason = (string) ($request->toArray()['reason'] ?? 'requested');

        // Queued, never run inline: a hub asking politely must not be able to block on
        // this station's USB bus.
        $this->bus->dispatch(new RescanDevicesMessage($reason !== '' ? $reason : 'requested'));

        return new JsonResponse(['ok' => true, 'queued' => true], 202);
    }
}
