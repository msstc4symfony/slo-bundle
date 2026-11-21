<?php

declare(strict_types=1);

use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Catalog\List\Action as ListAction;
use Msstc4Symfony\SloBundle\Application\Catalog\Validate\Action as ValidateAction;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Action as CoverageAction;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Action as RecordEventAction;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\ClockInterface;
use Msstc4Symfony\SloBundle\Application\Port\EventWriterInterface;
use Msstc4Symfony\SloBundle\Application\Port\OperationMonitorInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Application\Port\RoutePointIndexInterface;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Service\EventClassifier;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Logging\FailSafeLogger;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\HttpSloObserver;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MessengerSloObserver;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsEventWriter;
use Msstc4Symfony\SloBundle\Infrastructure\Operation\HrtimeClock;
use Msstc4Symfony\SloBundle\Infrastructure\Operation\OperationMonitor;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RoutePointIndex;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouterRouteCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\SloCacheWarmer;
use Msstc4Symfony\SloBundle\Presentation\Command\CoverageCommand;
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
    ;
    $services->alias(SloCatalogInterface::class, CompiledSloCatalog::class);
    $services->alias(CatalogDiagnosticsInterface::class, CompiledSloCatalog::class);

    $services->set(RevisionCalculator::class);
    $services->set(RouterRouteCatalog::class);
    $services->alias(RouteCatalogInterface::class, RouterRouteCatalog::class);

    $services->set(MetricBindingResolver::class);
    $services->set(EventClassifier::class);

    $services->set(MetricsEventWriter::class);
    $services->alias(EventWriterInterface::class, MetricsEventWriter::class);
    $services->set(FailSafeLogger::class)->tag('monolog.logger', ['channel' => 'slo_bundle']);
    $services->set(RecordEventAction::class)
        ->arg('$logger', service(FailSafeLogger::class))
        ->tag('kernel.reset', ['method' => 'reset'])
    ;

    // Observers are tagged by metrics-bundle autoconfiguration.
    $services->set(RoutePointIndex::class)->arg('$logger', service(FailSafeLogger::class));
    $services->alias(RoutePointIndexInterface::class, RoutePointIndex::class);
    $services->set(HttpSloObserver::class)->arg('$logger', service(FailSafeLogger::class));
    $services->set(MessengerSloObserver::class)->arg('$logger', service(FailSafeLogger::class));

    $services->set(HrtimeClock::class);
    $services->alias(ClockInterface::class, HrtimeClock::class);
    $services->set(OperationMonitor::class)
        ->arg('$logger', service(FailSafeLogger::class))
        ->tag('kernel.reset', ['method' => 'reset'])
    ;
    $services->alias(OperationMonitorInterface::class, OperationMonitor::class)->public();

    $services->set(ListAction::class);
    $services->set(ValidateAction::class);
    $services->set(CoverageAction::class);
    $services->set(CoverageCommand::class)
        ->arg('$ignoredRoutes', param('msstc4symfony_slo.coverage.http_routes.ignored_routes'))
        ->arg('$httpRequiredPercent', param('msstc4symfony_slo.coverage.http_routes.required_percent'))
        ->arg('$messengerRequiredPercent', param('msstc4symfony_slo.coverage.messenger_handlers.required_percent'))
        ->arg('$logicRequiredPercent', param('msstc4symfony_slo.coverage.logic_actions.required_percent'))
    ;
    $services->set(ListCommand::class);
    $services->set(ValidateCommand::class);
    $services->set(SloCacheWarmer::class)
        ->tag('kernel.cache_warmer')
        ->tag('monolog.logger', ['channel' => 'slo_bundle'])
    ;
};
