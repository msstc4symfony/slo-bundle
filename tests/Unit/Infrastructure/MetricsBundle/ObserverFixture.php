<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\MetricsBundle;

use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Binding\RevisionResolver;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Action;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Service\EventClassifier;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsBundleSeriesCatalog;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Evaluation\RecordEvent\FixedPrecomputedRevisions;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Evaluation\RecordEvent\InMemoryEventWriter;
use Psr\Log\LoggerInterface;

final class ObserverFixture
{
    public static function action(CompiledSloCatalog $catalog, RouteCatalogInterface $routes, InMemoryEventWriter $writer, LoggerInterface $logger): Action
    {
        return new Action($catalog, new MetricBindingResolver($routes, $catalog, new MetricsBundleSeriesCatalog()), new RevisionResolver(new RevisionCalculator(), $catalog), new EventClassifier(), $writer, $logger, new FixedPrecomputedRevisions());
    }

    /**
     * @return list<string> "<objective>:<result>" per write, sorted
     */
    public static function results(InMemoryEventWriter $writer): array
    {
        $results = array_map(static fn (array $write): string => $write[1] . ':' . $write[3], $writer->writes);
        sort($results);

        return $results;
    }
}
