<?php

declare(strict_types=1);

namespace Survos\DepotBundle;

use Survos\DepotBundle\Realtime\Event\OcrCompleted;
use Survos\DepotBundle\Realtime\EventNameResolver;
use Survos\DepotBundle\Realtime\EventPublisherFactory;
use Survos\DepotBundle\Realtime\EventPublisherInterface;
use Survos\DepotBundle\Realtime\EventSerializer;
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Survos\Kit\Traits\HasConfigurableRoutes;
use Survos\Kit\Traits\HasDoctrineEntities;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

/**
 * Graduated from depot's own packages/depot-bundle path package -- consumed
 * by depot itself (scan-appliance orchestration: Command/Controller/Service)
 * and by ssai (central hub: only the Realtime/ event bus). ssai MUST set
 * `appliance_enabled: false` in its own config/packages/survos_depot.yaml --
 * the appliance classes reference depot's own App\Entity\Capture /
 * App\Workflow\CaptureWFDefinition and fail container compilation outright
 * anywhere else (confirmed live registering this bundle in ssai without the
 * flag: ClassNotFoundError on App\Workflow\CaptureWFDefinition, plus
 * ScanIngestController's routes collide with ssai's own
 * App\Controller\Internal\ScanIngestController at POST /internal/scans).
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
            ->booleanNode('appliance_enabled')->defaultTrue()
                ->info(
                    'Scan-appliance orchestration (Command/Controller/Service -- ScanJobRunner, '
                    . 'ScanIngestController, etc.), all coupled to depot\'s own App\Entity\Capture / '
                    . 'App\Workflow\CaptureWFDefinition. False for any app that only wants Realtime/ '
                    . '(e.g. ssai) -- those classes reference types that do not exist outside depot '
                    . 'itself and fail container compilation if registered elsewhere.',
                )
            ->end()
        ;
        // Defaults resolve straight from the conventional DEPOT_EVENTS_*/DEPOT_NODE_ID
        // env vars (see EVENT_DEFAULT_PARAMS below) -- an app that sets those in its
        // own .env needs zero config/packages/survos_depot.yaml. Overriding a value
        // still works normally, e.g. `survos_depot: { events: { channel: other } }`.
        $children
            ->arrayNode('events')
                ->info('Ephemeral Redis Pub/Sub event bus -- see Survos\DepotBundle\Realtime. Never authoritative.')
                ->addDefaultsIfNotSet()
                ->children()
                    ->booleanNode('enabled')->defaultValue('%env(bool:default:survos_depot.default_events_enabled:DEPOT_EVENTS_ENABLED)%')
                        ->info('False, or an empty dsn, fall back to NullEventPublisher.')
                    ->end()
                    ->scalarNode('dsn')->defaultValue('%env(default:survos_depot.default_events_dsn:DEPOT_EVENTS_DSN)%')
                        ->info('Redis DSN, e.g. redis://127.0.0.1:6379. Empty disables publishing.')
                    ->end()
                    ->scalarNode('channel')->defaultValue('%env(default:survos_depot.default_events_channel:DEPOT_EVENTS_CHANNEL)%')->end()
                    ->scalarNode('node_id')->defaultValue('%env(default:survos_depot.default_events_node_id:DEPOT_NODE_ID)%')
                        ->info('Identifies the publishing node in every event envelope, e.g. depot-rapp, tac-laptop, server. Required whenever events are enabled with a dsn -- see EventPublisherFactory.')
                    ->end()
                ->end()
            ->end()
        ;
        $children->end();
    }

    /**
     * Fallback values used by configure()'s %env(default:PARAM:VAR)% defaults
     * when the app hasn't set the corresponding env var at all. Registered as
     * real container parameters (loadExtension() runs before the env
     * placeholders above get resolved at compile time).
     */
    private const EVENT_DEFAULT_PARAMS = [
        'survos_depot.default_events_enabled' => true,
        'survos_depot.default_events_dsn' => '',
        'survos_depot.default_events_channel' => 'depot.events',
        'survos_depot.default_events_node_id' => '',
    ];

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        foreach (self::EVENT_DEFAULT_PARAMS as $name => $value) {
            $builder->setParameter($name, $value);
        }

        $this->captureRouteConfig($config);

        if ($config['appliance_enabled']) {
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
        }

        $this->registerRealtimeEvents($config['events'], $builder);
    }

    /**
     * Deliberately NOT part of the Service/ auto-scan above: EventPublisherInterface
     * is registered via a runtime factory (EventPublisherFactory::create()), not a
     * PHP branch here -- %env(...)% config values (enabled/dsn/node_id) only resolve
     * to real values when a service is actually instantiated, not while the
     * container extension is being loaded, so deciding Null-vs-Redis with a plain
     * `if` on $config here would silently always take one branch AND leave the env
     * var referenced-but-never-consumed, which Symfony's compiler rejects outright.
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

        $builder->register(EventPublisherInterface::class, EventPublisherInterface::class)
            ->setFactory([EventPublisherFactory::class, 'create'])
            ->setArgument('$enabled', $config['enabled'])
            ->setArgument('$dsn', $config['dsn'])
            ->setArgument('$channel', $config['channel'])
            ->setArgument('$nodeId', $config['node_id'])
            ->setAutowired(true); // fills $serializer, $logger
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
