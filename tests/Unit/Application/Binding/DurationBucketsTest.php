<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Binding;

use Msstc4Symfony\SloBundle\Application\Binding\DurationBuckets;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsBundleSeriesCatalog;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use PHPUnit\Framework\TestCase;

final class DurationBucketsTest extends TestCase
{
    public function testJoinsTheHistogramOfThePointTypeWithItsRegisteredBuckets(): void
    {
        $series = new MetricsBundleSeriesCatalog();
        $histogram = $series->histogramFor(PointType::LogicAction);
        self::assertNotNull($histogram);
        $catalog = CatalogFixture::build(static function (): void {
        }, new CatalogExtras(metricBuckets: [$histogram => [0.1, 1.0]]));

        self::assertSame([0.1, 1.0], DurationBuckets::of($series, $catalog, PointType::LogicAction));
        self::assertNull(DurationBuckets::of($series, $catalog, PointType::HttpRoute));
        self::assertNull(DurationBuckets::of($series, $catalog, PointType::Operation));
    }
}
