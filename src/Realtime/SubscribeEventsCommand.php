<?php

declare(strict_types=1);

namespace Survos\DepotBundle\Realtime;

use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Long-running: blocks on \Redis::subscribe() until interrupted (Ctrl+C).
 * Not managed by this process -- start it yourself, same as
 * messenger:consume (see depot's own AGENTS.md / handoff docs for why).
 *
 * Registered explicitly (not autoconfigure-only) in
 * SurvosDepotBundle::registerRealtimeEvents(), which passes $dsn/$channel
 * from the resolved events.* config -- unconditional, not gated by
 * appliance_enabled, since ssai needs this command too.
 */
#[AsCommand('depot:events:subscribe', 'Subscribe to the depot.events Redis Pub/Sub channel and dispatch received events locally')]
final class SubscribeEventsCommand extends Command
{
    public function __construct(
        private readonly string $dsn,
        private readonly string $channel,
        private readonly RedisEventSubscriber $subscriber,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $dsn = trim($this->dsn);
        if ('' === $dsn) {
            $io->error('events.dsn is empty -- nothing to subscribe to. Set DEPOT_EVENTS_DSN.');

            return Command::FAILURE;
        }

        $io->text(sprintf('Subscribing to "%s" on %s ... (Ctrl+C to stop)', $this->channel, $dsn));

        $redis = RedisAdapter::createConnection($dsn);
        $redis->subscribe([$this->channel], function (\Redis $redis, string $channel, string $message) use ($io): void {
            $io->text(sprintf('<comment>%s</comment> %s', $channel, $message));
            $this->subscriber->handle($message);
        });

        return Command::SUCCESS;
    }
}
