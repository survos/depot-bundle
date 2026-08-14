<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Realtime;

use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * Registered as EventPublisherInterface's factory in
 * SurvosDepotBundle::registerRealtimeEvents(). Deliberately a runtime
 * factory method, not a PHP branch inside loadExtension() -- %env(...)%
 * config values (enabled/dsn/node_id) only resolve to real values when a
 * service is actually instantiated, not while the container extension is
 * being loaded, so the Null-vs-Redis decision has to live here.
 */
final class EventPublisherFactory
{
    public static function create(
        bool $enabled,
        string $dsn,
        string $channel,
        string $nodeId,
        EventSerializer $serializer,
        LoggerInterface $logger,
    ): EventPublisherInterface {
        $dsn = trim($dsn);
        if (!$enabled || '' === $dsn) {
            return new NullEventPublisher();
        }

        if ('' === trim($nodeId)) {
            throw new \LogicException(
                'survos_depot: events.node_id must be set whenever events.enabled is true and events.dsn is '
                . 'non-empty -- every published envelope needs a source. Set it in '
                . "config/packages/survos_depot.yaml, e.g. node_id: '%env(DEPOT_NODE_ID)%'.",
            );
        }

        return new RedisEventPublisher(
            RedisAdapter::createConnection($dsn),
            $channel,
            $serializer,
            $logger,
        );
    }
}
