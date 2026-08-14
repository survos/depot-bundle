<?php

declare(strict_types=1);

namespace Survos\DepotBundle;

use Survos\DepotBundle\Realtime\Event\OcrCompleted;
use Survos\DepotBundle\Realtime\EventNameResolver;
use Survos\DepotBundle\Realtime\EventPublisherInterface;
use Survos\DepotBundle\Realtime\EventSerializer;
use Survos\DepotBundle\Realtime\NullEventPublisher;
use Survos\DepotBundle\Realtime\RedisEventPublisher;
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\Kit\Traits\HasConfigurableRoutes;
use Survos\Kit\Traits\HasDoctrineEntities;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Graduated from depot's own packages/depot-bundle path package -- consumed
 * by depot itself (scan-appliance orchestration: Command/Controller/Service)
 * and by ssai (central hub: only the Realtime/ event bus). ssai should set
 * `routes_enabled: false` in its own config/packages/survos_depot.yaml --
 * this bundle's Controller/ routes (e.g. POST /internal/scans) collide with
 * ssai's own App\Controller\Internal\ScanIngestController at the same path.
 *
 * src/Entity/ is empty for now (Capture stays in depot's own App\, predates
 * this bundle) -- HasDoctrineEntities is wired ahead of that so the next
 * entity (e.g. an accession counter) needs zero additional bundle wiring.
 */
#[RequiredBundle(SurvosKitBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosDepotBundle extends AbstractSurvosBundle
{
    use HasConfigurableRoutes;
    use HasDoctrineEntities;

    public function configure(DefinitionConfigurator $definition): void
    {
        $children = $definition->rootNode()->children();
        // Empty default prefix: existing #[Route] attributes already hardcode
        // full paths (/internal/scan-jobs/trigger, /internal/scans) rather
        // than a bundle-relative suffix, so a non-empty prefix here would
        // double it up.
        $this->addRouteOptions($children, '');
        $children
            ->arrayNode('events')
                ->info('Ephemeral Redis Pub/Sub event bus -- see Survos\DepotBundle\Realtime. Never authoritative.')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('enabled')->defaultTrue()
                        ->info('False, or an empty dsn, fall back to NullEventPublisher.')
                    ->end()
                    ->scalarNode('dsn')->defaultValue('')
                        ->info('Redis DSN, e.g. redis://127.0.0.1:6379. Empty disables publishing.')
                    ->end()
                    ->scalarNode('channel')->defaultValue('depot.events')->end()
                    ->scalarNode('node_id')->isRequired()
                        ->info('Identifies the publishing node in every event envelope, e.g. depot-rapp, tac-laptop, server.')
                    ->end()
                ->end()
            ->end()
        ;
        $children->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $this->captureRouteConfig($config);
        parent::loadExtension($config, $container, $builder); // auto-scans Command/, Controller/
        $this->registerRouteLoader($builder);

        // AbstractSurvosBundle's own auto-scan only covers Command/ and
        // Controller/ -- Service/ needs explicit loading. No dedicated
        // MessageHandler/ dir: message handlers live directly on the
        // relevant service (#[AsMessageHandler] is pure-attribute in modern
        // Symfony, independent of class location). Message/ and Util/ are
        // plain DTOs/static helpers, not services.
        $namespace = (new \ReflectionClass($this))->getNamespaceName() . '\\';
        $serviceDir = $this->bundleRootPath() . '/src/Service/';
        if (is_dir($serviceDir)) {
            $container->services()
                ->defaults()->autowire()->autoconfigure()
                ->load($namespace . 'Service\\', $serviceDir);
        }

        $this->registerRealtimeEvents($config['events'], $builder);
    }

    /**
     * Deliberately NOT part of the Service/ auto-scan above: which
     * implementation answers EventPublisherInterface depends on config
     * (enabled/dsn), so it's registered explicitly here rather than
     * left to autowiring to guess between Null/RedisEventPublisher.
     *
     * @param array{enabled: bool, dsn: string, channel: string, node_id: string} $config
     */
    private function registerRealtimeEvents(array $config, ContainerBuilder $builder): void
    {
        $builder->register(EventNameResolver::class, EventNameResolver::class)
            ->setArgument('$map', [
                // Phase 3+ registers more event DTOs here as they're added.
                OcrCompleted::class => 'asset.ocr.completed',
            ]);

        $builder->register(EventSerializer::class, EventSerializer::class)
            ->setAutowired(true)
            ->setArgument('$nodeId', $config['node_id']);

        $dsn = trim($config['dsn']);
        if ($config['enabled'] && '' !== $dsn) {
            $builder->register('.survos_depot.realtime.redis_client', \Redis::class)
                ->setFactory([RedisAdapter::class, 'createConnection'])
                ->setArguments([$dsn]);

            $builder->register(EventPublisherInterface::class, RedisEventPublisher::class)
                ->setArgument('$redis', new Reference('.survos_depot.realtime.redis_client'))
                ->setArgument('$channel', $config['channel'])
                ->setAutowired(true); // fills $serializer, $logger
        } else {
            $builder->register(EventPublisherInterface::class, NullEventPublisher::class);
        }
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $this->addRouteLoaderCompilerPass($container);
    }

    protected function twigNamespace(): ?string
    {
        return null; // no templates in this bundle yet
    }
}
