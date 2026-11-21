<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Discovery\SloDeclaration;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;

/**
 * Read side of the catalog that reports problems instead of serving the runtime.
 *
 * @internal
 */
interface CatalogDiagnosticsInterface
{
    /**
     * Names declared differently on several points (ruling 1); absent from the catalog's definitions.
     *
     * @return array<non-empty-string, non-empty-list<SloDeclaration>> sorted by name
     */
    public function conflicts(): array;

    /**
     * SLOs merged from identical declarations on more than one point.
     *
     * @return array<non-empty-string, non-empty-list<PointId>>
     */
    public function shared(): array;

    public function extras(): CatalogExtras;

    /**
     * Cheap runtime read of CatalogExtras::$hasHttpPoints, without hydrating the CLI-only data.
     */
    public function hasHttpPoints(): bool;

    /**
     * Cheap runtime read of CatalogExtras::$failureTransports.
     *
     * @return list<non-empty-string>
     */
    public function failureTransports(): array;

    /**
     * Cheap runtime read of CatalogExtras::$metricBuckets: the buckets of a registered histogram, null when the metric
     * is not registered.
     *
     * @param non-empty-string $metric
     *
     * @return list<float>|null
     */
    public function metricBuckets(string $metric): ?array;

    /**
     * Cheap runtime read of an index over CatalogExtras::$taggedPoints: the `metric_label` of a tagged point.
     *
     * @return non-empty-string|null
     */
    public function metricLabelOf(PointId $point): ?string;

    /**
     * Cheap runtime read of CatalogExtras::$controllerServices: the class behind a controller service id, null when the
     * id is the class name itself.
     *
     * @param non-empty-string $serviceId
     *
     * @return class-string|null
     */
    public function controllerServiceOf(string $serviceId): ?string;

    /**
     * Cheap runtime read of an index over CatalogExtras::$handlers: the handled message classes metrics-bundle writes
     * into the `message` label as $label (SeriesCatalogInterface::messageLabel() at compile time).
     *
     * @param non-empty-string $label
     *
     * @return list<non-empty-string> sorted
     */
    public function handledMessageClassesLabelled(string $label): array;
}
