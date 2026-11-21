<?php

declare(strict_types=1);

use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Binding\RevisionResolver;
use Msstc4Symfony\SloBundle\Application\Catalog\List\Action as ListAction;
use Msstc4Symfony\SloBundle\Application\Catalog\Validate\Action as ValidateAction;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Action as CoverageAction;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Action as RecordEventAction;
use Msstc4Symfony\SloBundle\Application\Generation\BoundObjectiveCollector;
use Msstc4Symfony\SloBundle\Application\Generation\BurnRatePolicy;
use Msstc4Symfony\SloBundle\Application\Generation\Generate\Action as GenerateAction;
use Msstc4Symfony\SloBundle\Application\Generation\Generate\GenerationConfig;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\Action as GenerateDashboardsAction;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateRules\Action as GenerateRulesAction;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateSloth\Action as GenerateSlothAction;
use Msstc4Symfony\SloBundle\Application\Generation\SyncFiles\Action as SyncFilesAction;
use Msstc4Symfony\SloBundle\Application\Operation\OperationMonitor;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\EventRecorderInterface;
use Msstc4Symfony\SloBundle\Application\Port\EventWriterInterface;
use Msstc4Symfony\SloBundle\Application\Port\FileWriterInterface;
use Msstc4Symfony\SloBundle\Application\Port\GrafanaFileBuilderInterface;
use Msstc4Symfony\SloBundle\Application\Port\MonotonicClockInterface;
use Msstc4Symfony\SloBundle\Application\Port\OperationMonitorInterface;
use Msstc4Symfony\SloBundle\Application\Port\PrecomputedRevisionsInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Application\Port\RoutePointIndexInterface;
use Msstc4Symfony\SloBundle\Application\Port\RuleFileBuilderInterface;
use Msstc4Symfony\SloBundle\Application\Port\SeriesCatalogInterface;
use Msstc4Symfony\SloBundle\Application\Port\SlothSpecBuilderInterface;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Service\EventClassifier;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Clock\HrtimeClock;
use Msstc4Symfony\SloBundle\Infrastructure\Filesystem\FileWriter;
use Msstc4Symfony\SloBundle\Infrastructure\Grafana\GrafanaFileBuilder;
use Msstc4Symfony\SloBundle\Infrastructure\Logging\FailSafeLogger;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\DeliveryStatusMapper;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\DeliveryStatusMapperInterface;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\HttpSloObserver;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MessengerSloObserver;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsBundleSeriesCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsEventWriter;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\PromQlBuilder;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\RuleGroupsBuilder;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexBuilder;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexFile;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexStoreInterface;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\WarmedRevisions;
use Msstc4Symfony\SloBundle\Infrastructure\Sloth\SlothSpecBuilder;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RoutePointIndex;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouterRouteCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouterRouteReader;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\SloCacheWarmer;
use Msstc4Symfony\SloBundle\Infrastructure\Yaml\DeterministicYaml;
use Msstc4Symfony\SloBundle\Presentation\Command\CoverageCommand;
use Msstc4Symfony\SloBundle\Presentation\Command\GenerateCommand;
use Msstc4Symfony\SloBundle\Presentation\Command\ListCommand;
use Msstc4Symfony\SloBundle\Presentation\Command\ValidateCommand;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\abstract_arg;
use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    $services->set(CompiledSloCatalog::class)
        ->arg('$catalog', abstract_arg('catalog dump, set by CollectSloDefinitionsPass'))
        ->arg('$logger', service(FailSafeLogger::class))
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
    $services->alias(SloCatalogInterface::class, CompiledSloCatalog::class);
    $services->alias(CatalogDiagnosticsInterface::class, CompiledSloCatalog::class);

    $services->set(RevisionCalculator::class);
    $services->set(RouterRouteReader::class)->arg('$router', service('router')->nullOnInvalid());
    $services->set(RouteIndexFile::class)
        ->arg('$cacheDir', param('kernel.cache_dir'))
        ->arg('$debug', param('kernel.debug'))
        ->arg('$fingerprint', abstract_arg('hash of the catalog dump, set by CollectSloDefinitionsPass'))
        ->arg('$logger', service(FailSafeLogger::class))
    ;
    $services->alias(RouteIndexStoreInterface::class, RouteIndexFile::class);
    $services->set(RouterRouteCatalog::class);
    $services->alias(RouteCatalogInterface::class, RouterRouteCatalog::class);
    $services->set(WarmedRevisions::class);
    $services->alias(PrecomputedRevisionsInterface::class, WarmedRevisions::class);

    $services->set(MetricsBundleSeriesCatalog::class);
    $services->alias(SeriesCatalogInterface::class, MetricsBundleSeriesCatalog::class);
    $services->set(MetricBindingResolver::class);
    $services->set(RevisionResolver::class);
    $services->set(EventClassifier::class);

    $services->set(MetricsEventWriter::class);
    $services->alias(EventWriterInterface::class, MetricsEventWriter::class);
    $services->set(FailSafeLogger::class)->tag('monolog.logger', ['channel' => 'slo_bundle']);
    $services->set(RecordEventAction::class)
        ->arg('$logger', service(FailSafeLogger::class))
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
    $services->alias(EventRecorderInterface::class, RecordEventAction::class)->public();

    // Observers are tagged by metrics-bundle autoconfiguration.
    $services->set(RoutePointIndex::class)
        ->arg('$logger', service(FailSafeLogger::class))
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
    $services->alias(RoutePointIndexInterface::class, RoutePointIndex::class);
    $services->set(HttpSloObserver::class)->arg('$logger', service(FailSafeLogger::class));
    $services->set(DeliveryStatusMapper::class)
        ->arg('$logger', service(FailSafeLogger::class))
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
    $services->alias(DeliveryStatusMapperInterface::class, DeliveryStatusMapper::class);
    $services->set(MessengerSloObserver::class)->arg('$logger', service(FailSafeLogger::class));

    $services->set(HrtimeClock::class);
    $services->alias(MonotonicClockInterface::class, HrtimeClock::class);

    $services->set(OperationMonitor::class)
        ->arg('$logger', service(FailSafeLogger::class))
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
    $services->alias(OperationMonitorInterface::class, OperationMonitor::class)->public();

    $services->set(ListAction::class);
    $services->set(ValidateAction::class)
        ->arg('$prometheusRules', param('msstc4symfony_slo.generation.prometheus.rules'))
        ->arg('$prometheusAlerts', param('msstc4symfony_slo.generation.prometheus.alerts'))
    ;
    $services->set(CoverageAction::class);
    $services->set(CoverageCommand::class)
        ->arg('$ignoredRoutes', param('msstc4symfony_slo.coverage.http_routes.ignored_routes'))
        ->arg('$httpRequiredPercent', param('msstc4symfony_slo.coverage.http_routes.required_percent'))
        ->arg('$messengerRequiredPercent', param('msstc4symfony_slo.coverage.messenger_handlers.required_percent'))
        ->arg('$logicRequiredPercent', param('msstc4symfony_slo.coverage.logic_actions.required_percent'))
    ;
    $services->set(BurnRatePolicy::class);
    $services->set(BoundObjectiveCollector::class);
    $services->set(PromQlBuilder::class);
    $services->set(DeterministicYaml::class);
    $services->set(RuleGroupsBuilder::class);
    $services->alias(RuleFileBuilderInterface::class, RuleGroupsBuilder::class);
    $services->set(GrafanaFileBuilder::class)
        ->factory([GrafanaFileBuilder::class, 'standard'])
        ->arg('$datasourceUid', param('msstc4symfony_slo.generation.grafana.datasource_uid'))
        ->arg('$folder', param('msstc4symfony_slo.generation.grafana.folder'))
    ;
    $services->alias(GrafanaFileBuilderInterface::class, GrafanaFileBuilder::class);
    $services->set(FileWriter::class);
    $services->alias(FileWriterInterface::class, FileWriter::class);
    $services->set(SlothSpecBuilder::class);
    $services->alias(SlothSpecBuilderInterface::class, SlothSpecBuilder::class);
    $services->set(GenerateRulesAction::class);
    $services->set(GenerateSlothAction::class);
    $services->set(GenerateDashboardsAction::class)
        ->arg('$ignoredRoutes', param('msstc4symfony_slo.coverage.http_routes.ignored_routes'))
    ;
    $services->set(SyncFilesAction::class);
    $services->set(GenerationConfig::class)
        ->arg('$application', param('msstc4symfony_slo.generation.selector.application'))
        ->arg('$components', param('msstc4symfony_slo.generation.selector.components'))
        ->arg('$outputDir', param('msstc4symfony_slo.generation.output_dir'))
        ->arg('$prometheusRules', param('msstc4symfony_slo.generation.prometheus.rules'))
        ->arg('$prometheusAlerts', param('msstc4symfony_slo.generation.prometheus.alerts'))
        ->arg('$noEventsAlertHours', param('msstc4symfony_slo.generation.prometheus.no_events_alert_hours'))
        ->arg('$sloth', param('msstc4symfony_slo.generation.sloth'))
    ;
    $services->set(GenerateAction::class);
    $services->set(GenerateCommand::class);
    $services->set(ListCommand::class);
    $services->set(ValidateCommand::class);
    $services->set(RouteIndexBuilder::class)->arg('$logger', service(FailSafeLogger::class));
    $services->set(SloCacheWarmer::class)
        ->arg('$logger', service(FailSafeLogger::class))
        ->tag('kernel.cache_warmer')
    ;
};
