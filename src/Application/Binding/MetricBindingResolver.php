<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Binding;

use Closure;
use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Application\Port\SeriesCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Msstc4Symfony\SloBundle\Domain\ValueObject\LatencyThreshold;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;

/**
 * Picks per objective between existing metrics-bundle series (Strategy A) and dedicated counters (Strategy B), spec 5.4.
 * Runs on the request path when no warmed revisions exist: it reads only cheap catalog facts, never extras().
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
        $build = $this->existingSeries($definition, $objective);

        return $build instanceof Closure ? Binding::existingSeries($build()) : Binding::dedicated();
    }

    /**
     * Decides without building the series binding (matchers, patterns): the request path only needs the strategy.
     */
    public function isDedicated(SloDefinition $definition, ObjectiveKind $kind): bool
    {
        $objective = $definition->objective($kind);
        if (!$objective instanceof Objective) {
            throw new InvalidArgumentException(\sprintf('SLO "%s" has no "%s" objective.', $definition->name()->value, $kind->value));
        }

        return !$this->existingSeries($definition, $objective) instanceof Closure;
    }

    /**
     * True when the point type has a duration histogram and the objective's threshold is none of its buckets.
     */
    public function thresholdMissesBucket(SloDefinition $definition, Objective $objective): bool
    {
        return $this->histogramFor($definition->pointType()) !== null
            && $objective->threshold instanceof LatencyThreshold
            && $this->bucket($definition->pointType(), $objective) === null;
    }

    /**
     * @return non-empty-string|null
     */
    public function histogramFor(PointType $type): ?string
    {
        return $this->series->histogramFor($type);
    }

    /**
     * @return (Closure(): SeriesBinding)|null builds the existing series that express the objective, null when it needs dedicated counters
     */
    private function existingSeries(SloDefinition $definition, Objective $objective): ?Closure
    {
        if ($definition->strategyPreference() === StrategyPreference::Dedicated || $objective->kind === ObjectiveKind::Combined) {
            return null;
        }

        return match ($definition->pointType()) {
            PointType::HttpRoute => $this->http($definition, $objective),
            PointType::MessengerMessage => $this->messenger($definition, $objective),
            PointType::LogicAction => $this->logic($definition, $objective),
            PointType::Operation => null,
        };
    }

    /**
     * @return (Closure(): SeriesBinding)|null
     */
    private function http(SloDefinition $definition, Objective $objective): ?Closure
    {
        $routeNames = $this->routeNames($definition);
        if ($routeNames === []) {
            return null;
        }
        $classification = $definition->classification();

        if ($objective->kind === ObjectiveKind::Availability) {
            return fn (): SeriesBinding => $this->series->httpAvailability($routeNames, $classification->badStatuses, $classification->ineligibleStatuses);
        }

        // The histogram has no status label: only "every eligible event" can be read from it.
        if (!$objective->includeErrors || !$classification->ineligibleStatuses->isEmpty()) {
            return null;
        }
        $bucket = $this->bucket(PointType::HttpRoute, $objective);

        return $bucket === null ? null : fn (): SeriesBinding => $this->series->httpLatency($routeNames, $bucket);
    }

    /**
     * @return (Closure(): SeriesBinding)|null
     */
    private function messenger(SloDefinition $definition, Objective $objective): ?Closure
    {
        if ($definition->classification()->countAttempts || $this->sharesMessageLabel($definition)) {
            return null;
        }
        $messages = array_map(static fn (PointId $point): string => $point->id, $definition->points());

        if ($objective->kind === ObjectiveKind::Availability) {
            return fn (): SeriesBinding => $this->series->messengerAvailability($messages, $this->diagnostics->failureTransports());
        }

        $bucket = $this->bucket(PointType::MessengerMessage, $objective);

        return $bucket === null ? null : fn (): SeriesBinding => $this->series->messengerLatency($messages, $this->diagnostics->failureTransports(), $bucket);
    }

    /**
     * @return (Closure(): SeriesBinding)|null
     */
    private function logic(SloDefinition $definition, Objective $objective): ?Closure
    {
        if (DurationBuckets::of($this->series, $this->diagnostics, PointType::LogicAction) === null) {
            return null;
        }

        $labels = [];
        foreach ($definition->points() as $point) {
            $label = $this->diagnostics->metricLabelOf($point);
            if ($label === null) {
                return null;
            }
            $labels[] = $label;
        }

        if ($objective->kind === ObjectiveKind::Availability) {
            // The series counts expected failures as good: it cannot express expectedFailureIsGood: false.
            return $definition->classification()->expectedFailureIsGood ? fn (): SeriesBinding => $this->series->logicAvailability($labels) : null;
        }
        $bucket = $this->bucket(PointType::LogicAction, $objective);

        return $bucket === null ? null : fn (): SeriesBinding => $this->series->logicLatency($labels, $bucket);
    }

    /**
     * @return non-empty-string|null
     */
    private function bucket(PointType $type, Objective $objective): ?string
    {
        if ($this->series->histogramFor($type) === null || !$objective->threshold instanceof LatencyThreshold) {
            return null;
        }

        return $this->series->bucketFor(DurationBuckets::of($this->series, $this->diagnostics, $type) ?? [], $objective->threshold);
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
    private function sharesMessageLabel(SloDefinition $definition): bool
    {
        $own = [];
        foreach ($definition->points() as $point) {
            $own[$point->id] = true;
        }
        foreach ($definition->points() as $point) {
            foreach ($this->diagnostics->handledMessageClassesLabelled($this->series->messageLabel($point->id)) as $messageClass) {
                if (!isset($own[$messageClass])) {
                    return true;
                }
            }
        }

        return false;
    }
}
