<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\RouteIndex;

use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Binding\RevisionResolver;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsBundleSeriesCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexBuilder;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\InMemoryRouteCatalog;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class RouteIndexFixture
{
    public static function builder(SloCatalogInterface $catalog, CatalogDiagnosticsInterface $diagnostics, LoggerInterface $logger = new NullLogger()): RouteIndexBuilder
    {
        return new RouteIndexBuilder(
            $catalog,
            new MetricBindingResolver(new InMemoryRouteCatalog(), $diagnostics, new MetricsBundleSeriesCatalog()),
            new RevisionResolver(new RevisionCalculator(), $diagnostics),
            $logger,
        );
    }
}
