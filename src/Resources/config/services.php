<?php

declare(strict_types=1);

use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Catalog\List\Action as ListAction;
use Msstc4Symfony\SloBundle\Application\Catalog\Validate\Action as ValidateAction;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Action as RecordEventAction;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\EventWriterInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Service\EventClassifier;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsEventWriter;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouterRouteCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\SloCacheWarmer;
use Msstc4Symfony\SloBundle\Presentation\Command\ListCommand;
use Msstc4Symfony\SloBundle\Presentation\Command\ValidateCommand;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\abstract_arg;

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
    $services->set(RecordEventAction::class)
        ->tag('kernel.reset', ['method' => 'reset'])
        ->tag('monolog.logger', ['channel' => 'slo_bundle'])
    ;

    $services->set(ListAction::class);
    $services->set(ValidateAction::class);
    $services->set(ListCommand::class);
    $services->set(ValidateCommand::class);
    $services->set(SloCacheWarmer::class)
        ->tag('kernel.cache_warmer')
        ->tag('monolog.logger', ['channel' => 'slo_bundle'])
    ;
};
