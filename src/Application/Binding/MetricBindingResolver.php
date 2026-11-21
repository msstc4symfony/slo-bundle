<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Binding;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Application\Port\SeriesCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Enum\Strategy;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Msstc4Symfony\SloBundle\Domain\ValueObject\LatencyThreshold;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;

/**
 * Picks per objective between existing metrics-bundle series (Strategy A) and dedicated counters (Strategy B), spec 5.4.
 *
 * @internal
 */
final readonly class MetricBindingResolver
{
    public function __construct(
        private RouteCatalogInterface $routes,
        private CatalogDiagnosticsInterface $diagnostics,
        private SeriesCatalogInterface $series,
    ) {
    }

    public function resolve(SloDefinition $definition, Objective $objective): Binding
    {
        if ($definition->strategy() === StrategyPreference::Dedicated || $objective->kind === ObjectiveKind::Combined) {
            return Binding::dedicated();
        }

        $extras = $this->diagnostics->extras();
        $series = match ($definition->pointType()) {
            PointType::HttpRoute => $this->http($definition, $objective, $extras),
            PointType::MessengerMessage => $this->messenger($definition, $objective, $extras),
            PointType::LogicAction => $this->logic($definition, $objective, $extras),
            PointType::Operation => null,
        };

        return $series instanceof SeriesBinding ? Binding::existingSeries($series) : Binding::dedicated();
    }

    public function isDedicated(SloDefinition $definition, ObjectiveKind $kind): bool
    {
        $objective = $definition->objective($kind);
        if (!$objective instanceof Objective) {
            throw new InvalidArgumentException(\sprintf('SLO "%s" has no "%s" objective.', $definition->name()->value, $kind->value));
        }

        return $this->resolve($definition, $objective)->strategy === Strategy::Dedicated;
    }

    /**
     * True when the point type has a duration histogram and the objective's threshold is none of its buckets.
     */
    public function thresholdMissesBucket(SloDefinition $definition, Objective $objective): bool
    {
        return $this->histogramFor($definition->pointType()) !== null
            && $objective->threshold instanceof LatencyThreshold
            && $this->bucket($definition->pointType(), $objective, $this->diagnostics->extras()) === null;
    }

    /**
     * @return non-empty-string|null
     */
    public function histogramFor(PointType $type): ?string
    {
        return $this->series->histogramFor($type);
    }

    private function http(SloDefinition $definition, Objective $objective, CatalogExtras $extras): ?SeriesBinding
    {
        $routeNames = $this->routeNames($definition);
        if ($routeNames === []) {
            return null;
        }
        $classification = $definition->classification();

        if ($objective->kind === ObjectiveKind::Availability) {
            return $this->series->httpAvailability($routeNames, $classification->badStatuses, $classification->ineligibleStatuses);
        }

        // The histogram has no status label: only "every eligible event" can be read from it.
        if (!$objective->includeErrors || !$classification->ineligibleStatuses->isEmpty()) {
            return null;
        }
        $bucket = $this->bucket(PointType::HttpRoute, $objective, $extras);

        return $bucket === null ? null : $this->series->httpLatency($routeNames, $bucket);
    }

    private function messenger(SloDefinition $definition, Objective $objective, CatalogExtras $extras): ?SeriesBinding
    {
        if ($definition->classification()->countAttempts || $this->sharesMessageLabel($definition, $extras)) {
            return null;
        }
        $messages = array_map(static fn (PointId $point): string => $point->id, $definition->points());

        if ($objective->kind === ObjectiveKind::Availability) {
            return $this->series->messengerAvailability($messages, $extras->failureTransports);
        }

        $bucket = $this->bucket(PointType::MessengerMessage, $objective, $extras);

        return $bucket === null ? null : $this->series->messengerLatency($messages, $extras->failureTransports, $bucket);
    }

    private function logic(SloDefinition $definition, Objective $objective, CatalogExtras $extras): ?SeriesBinding
    {
        $histogram = $this->series->histogramFor(PointType::LogicAction);
        if ($histogram === null || !isset($extras->metricBuckets[$histogram])) {
            return null;
        }

        $labels = [];
        foreach ($definition->points() as $point) {
            $label = $this->metricLabel($extras, $point);
            if ($label === null) {
                return null;
            }
            $labels[] = $label;
        }

        if ($objective->kind === ObjectiveKind::Availability) {
            // The series counts expected failures as good: it cannot express expectedFailureIsGood: false.
            return $definition->classification()->expectedFailureIsGood ? $this->series->logicAvailability($labels) : null;
        }
        $bucket = $this->bucket(PointType::LogicAction, $objective, $extras);

        return $bucket === null ? null : $this->series->logicLatency($labels, $objective->includeErrors, $bucket);
    }

    /**
     * @return non-empty-string|null
     */
    private function bucket(PointType $type, Objective $objective, CatalogExtras $extras): ?string
    {
        $histogram = $this->series->histogramFor($type);
        if ($histogram === null || !$objective->threshold instanceof LatencyThreshold) {
            return null;
        }

        return $this->series->bucketFor($extras->metricBuckets[$histogram] ?? [], $objective->threshold);
    }

    /**
     * @return list<non-empty-string>
     */
    private function routeNames(SloDefinition $definition): array
    {
        $names = [];
        foreach ($definition->points() as $point) {
            foreach ($this->routes->routesFor($point->id) as $route) {
                $names[$route->name] = true;
            }
        }
        $names = array_map(strval(...), array_keys($names));
        sort($names, SORT_STRING);

        return array_values(array_filter($names, static fn (string $name): bool => $name !== ''));
    }

    /**
     * Another handled message with the same `message` label value would be summed into the series.
     */
    private function sharesMessageLabel(SloDefinition $definition, CatalogExtras $extras): bool
    {
        $own = [];
        foreach ($definition->points() as $point) {
            $own[$point->id] = $this->series->messageLabel($point->id);
        }
        foreach ($extras->handlers as $handler) {
            if (!isset($own[$handler->messageClass]) && \in_array($this->series->messageLabel($handler->messageClass), $own, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return non-empty-string|null
     */
    private function metricLabel(CatalogExtras $extras, PointId $point): ?string
    {
        foreach ($extras->taggedPoints as $tagged) {
            if ($tagged->point->equals($point)) {
                return $tagged->metricLabel;
            }
        }

        return null;
    }
}
