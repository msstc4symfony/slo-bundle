<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Binding;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
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
    private const HTTP_COUNTER = 'http_response';

    private const HTTP_HISTOGRAM = 'request_duration_histogram_seconds';

    private const MESSENGER_COUNTER = 'messenger_message_handled';

    private const MESSENGER_HISTOGRAM = 'messenger_message_handling_duration_histogram_seconds';

    private const LOGIC_HISTOGRAM = 'logic_action_duration_histogram_seconds';

    private const LOGIC_NON_ERROR = 'success|expected_failure';

    public function __construct(
        private RouteCatalogInterface $routes,
        private CatalogDiagnosticsInterface $diagnostics,
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
        $histogram = $this->histogramFor($definition->pointType());

        return $histogram !== null
            && $objective->threshold instanceof LatencyThreshold
            && $this->bucketBound($this->diagnostics->extras()->metricBuckets[$histogram] ?? [], $objective) === null;
    }

    /**
     * @return non-empty-string|null
     */
    public function histogramFor(PointType $type): ?string
    {
        return match ($type) {
            PointType::HttpRoute => self::HTTP_HISTOGRAM,
            PointType::MessengerMessage => self::MESSENGER_HISTOGRAM,
            PointType::LogicAction => self::LOGIC_HISTOGRAM,
            PointType::Operation => null,
        };
    }

    private function http(SloDefinition $definition, Objective $objective, CatalogExtras $extras): ?SeriesBinding
    {
        $routeNames = $this->routeNames($definition);
        if ($routeNames === []) {
            return null;
        }
        $route = Matcher::regex('route', $this->alternation($routeNames));
        $classification = $definition->classification();

        if ($objective->kind === ObjectiveKind::Availability) {
            $bad = $classification->badStatuses->toRegex();
            $ineligible = $classification->ineligibleStatuses->toRegex();
            $notGood = implode('|', array_filter([$bad, $ineligible], static fn (string $part): bool => $part !== ''));

            return new SeriesBinding(
                self::HTTP_COUNTER,
                SeriesBinding::KIND_COUNTER,
                SeriesBinding::index($ineligible === '' ? [$route] : [$route, Matcher::notRegex('status', $ineligible)]),
                SeriesBinding::index($notGood === '' ? [] : [Matcher::notRegex('status', $notGood)]),
                SeriesBinding::index($bad === '' ? [] : [Matcher::regex('status', $bad)]),
                null,
            );
        }

        // The histogram has no status label: only "every eligible event" can be read from it.
        if (!$objective->includeErrors || !$classification->ineligibleStatuses->isEmpty()) {
            return null;
        }

        return $this->latency(self::HTTP_HISTOGRAM, [$route], $objective, $extras);
    }

    private function messenger(SloDefinition $definition, Objective $objective, CatalogExtras $extras): ?SeriesBinding
    {
        if ($definition->classification()->countAttempts || $this->sharesShortName($definition, $extras)) {
            return null;
        }

        $messages = array_map(static fn (PointId $point): string => self::shortName($point->id), $definition->points());
        $message = $this->exactOrAlternation('message', $messages);
        $failureTransports = $extras->failureTransports;
        sort($failureTransports, SORT_STRING);
        $transport = $failureTransports === [] ? [] : [Matcher::notRegex('transport', $this->alternation($failureTransports))];

        if ($objective->kind === ObjectiveKind::Availability) {
            return new SeriesBinding(
                self::MESSENGER_COUNTER,
                SeriesBinding::KIND_COUNTER,
                SeriesBinding::index([$message, Matcher::regex('status', 'handled|failed'), ...$transport]),
                SeriesBinding::index([Matcher::equals('status', 'handled')]),
                SeriesBinding::index([Matcher::equals('status', 'failed')]),
                null,
            );
        }

        // includeErrors is rejected for Messenger at compile time; the histogram could not express it anyway.
        if ($objective->includeErrors) {
            return null;
        }

        return $this->latency(self::MESSENGER_HISTOGRAM, [$message, Matcher::equals('status', 'handled'), ...$transport], $objective, $extras);
    }

    private function logic(SloDefinition $definition, Objective $objective, CatalogExtras $extras): ?SeriesBinding
    {
        if (!$definition->classification()->expectedFailureIsGood || !isset($extras->metricBuckets[self::LOGIC_HISTOGRAM])) {
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
        $action = $this->exactOrAlternation('action', $labels);

        if ($objective->kind === ObjectiveKind::Availability) {
            return new SeriesBinding(
                self::LOGIC_HISTOGRAM,
                SeriesBinding::KIND_HISTOGRAM,
                SeriesBinding::index([$action]),
                SeriesBinding::index([Matcher::regex('outcome', self::LOGIC_NON_ERROR)]),
                SeriesBinding::index([Matcher::equals('outcome', 'error')]),
                null,
            );
        }

        $eligible = $objective->includeErrors ? [] : [Matcher::regex('outcome', self::LOGIC_NON_ERROR)];

        return $this->latency(self::LOGIC_HISTOGRAM, [$action, ...$eligible], $objective, $extras);
    }

    /**
     * @param non-empty-string $histogram
     * @param list<Matcher> $matchers
     */
    private function latency(string $histogram, array $matchers, Objective $objective, CatalogExtras $extras): ?SeriesBinding
    {
        $le = $this->bucketBound($extras->metricBuckets[$histogram] ?? [], $objective);
        if ($le === null) {
            return null;
        }

        return new SeriesBinding($histogram, SeriesBinding::KIND_HISTOGRAM, SeriesBinding::index($matchers), [], [], $le);
    }

    /**
     * @param list<float> $buckets
     *
     * @return non-empty-string|null
     */
    private function bucketBound(array $buckets, Objective $objective): ?string
    {
        if (!$objective->threshold instanceof LatencyThreshold) {
            return null;
        }
        foreach ($buckets as $bucket) {
            $label = BucketLabel::fromBucket($bucket);
            if ($label->equalsMilliseconds($objective->threshold->milliseconds)) {
                return $label->value;
            }
        }

        return null;
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
     * metrics-bundle labels messages by short class name: another handled message with the same short name would be
     * summed into the series.
     */
    private function sharesShortName(SloDefinition $definition, CatalogExtras $extras): bool
    {
        $own = [];
        foreach ($definition->points() as $point) {
            $own[$point->id] = self::shortName($point->id);
        }
        foreach ($extras->handlers as $handler) {
            if (!isset($own[$handler->messageClass]) && \in_array(self::shortName($handler->messageClass), $own, true)) {
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

    /**
     * @param non-empty-string $label
     * @param non-empty-list<non-empty-string>|list<non-empty-string> $values
     */
    private function exactOrAlternation(string $label, array $values): Matcher
    {
        $values = array_values(array_unique($values));
        sort($values, SORT_STRING);

        return \count($values) === 1 ? Matcher::equals($label, $values[0]) : Matcher::regex($label, $this->alternation($values));
    }

    /**
     * @param list<non-empty-string> $values sorted
     */
    private function alternation(array $values): string
    {
        return implode('|', array_map(Matcher::escapeRegex(...), $values));
    }

    /**
     * @param non-empty-string $class
     *
     * @return non-empty-string
     */
    private static function shortName(string $class): string
    {
        $position = strrpos($class, '\\');
        $short = $position === false ? $class : substr($class, $position + 1);

        return $short === '' ? $class : $short;
    }
}
