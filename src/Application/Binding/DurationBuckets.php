<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Binding;

use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\SeriesCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;

/**
 * Joins the duration histogram of a point type (series catalog) with its registered buckets (compiled catalog).
 *
 * @internal
 */
final class DurationBuckets
{
    /**
     * @return list<float>|null null when the point type has no duration histogram or it is not registered
     */
    public static function of(SeriesCatalogInterface $series, CatalogDiagnosticsInterface $diagnostics, PointType $type): ?array
    {
        $histogram = $series->histogramFor($type);

        return $histogram === null ? null : $diagnostics->metricBuckets($histogram);
    }
}
