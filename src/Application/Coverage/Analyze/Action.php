<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Coverage\Analyze;

use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Discovery\HandlerInfo;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\CoverageStatus;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;

/**
 * Static coverage of the application by SLOs, spec 5.7. Reads only compile-time facts, never a time series.
 */
final readonly class Action
{
    private const LOGIC_HISTOGRAM = 'logic_action_duration_histogram_seconds';

    public function __construct(
        private SloCatalogInterface $catalog,
        private CatalogDiagnosticsInterface $diagnostics,
        private RouteCatalogInterface $routes,
    ) {
    }

    public function __invoke(Input $input): Output
    {
        $extras = $this->diagnostics->extras();
        $points = [
            ...$this->httpPoints($input, $extras),
            ...$this->messengerPoints($extras),
            ...$this->logicPoints($extras),
            ...$this->operationPoints($extras),
        ];
        usort($points, static fn (PointCoverage $a, PointCoverage $b): int => [array_search($a->kind, PointCoverage::KINDS, true), $a->point] <=> [array_search($b->kind, PointCoverage::KINDS, true), $b->point]);

        $slo = [];
        $measured = [];
        $invalid = 0;
        foreach (PointCoverage::KINDS as $kind) {
            $ofKind = array_filter($points, static fn (PointCoverage $p): bool => $p->kind === $kind);
            $count = static fn (CoverageStatus ...$statuses): int => \count(array_filter($ofKind, static fn (PointCoverage $p): bool => \in_array($p->status, $statuses, true)));
            $slo[$kind] = $this->percent($count(CoverageStatus::Monitored), \count($ofKind) - $count(CoverageStatus::Ignored, CoverageStatus::Unsupported));
            $measured[$kind] = $this->percent(\count(array_filter($ofKind, static fn (PointCoverage $p): bool => $p->measuredByUniversalMetric)), \count($ofKind));
            $invalid += $count(CoverageStatus::Invalid);
        }

        return new Output($points, $slo, $measured, $invalid);
    }

    /**
     * @return list<PointCoverage>
     */
    private function httpPoints(Input $input, CatalogExtras $extras): array
    {
        $points = [];
        foreach ($this->routes->all() as $route) {
            $point = $route->controller === null ? null : PointId::of(PointType::HttpRoute, $route->controller);
            $definitions = $point instanceof PointId ? $this->catalog->forPoint($point) : [];
            $systemRoute = \in_array($route->name, $extras->httpIgnoredRoutes, true) || str_starts_with($route->name, '_');
            $measured = !$systemRoute;

            $invalid = match (true) {
                $point instanceof PointId && $this->conflictReason($point) !== null => $this->conflictReason($point),
                $definitions !== [] && \in_array($route->name, $extras->httpIgnoredRoutes, true) => 'route is in the ignored routes of metrics-bundle (http.ignored_routes), it is never measured',
                default => null,
            };
            $ignore = match (true) {
                $systemRoute => 'system route',
                \in_array($route->name, $input->ignoredRoutes, true) => 'configured in coverage.http_routes.ignored_routes',
                default => $point instanceof PointId ? ($this->catalog->ignored()[$point->toString()] ?? null) : null,
            };

            $points[] = $this->classify(
                $route->name,
                PointCoverage::KIND_HTTP_ROUTE,
                $definitions,
                $invalid,
                $ignore,
                null,
                $measured,
            );
        }

        return $points;
    }

    /**
     * @return list<PointCoverage>
     */
    private function messengerPoints(CatalogExtras $extras): array
    {
        $points = [];
        foreach ($extras->handlers as $handler) {
            $message = PointId::of(PointType::MessengerMessage, $handler->messageClass);

            $points[] = $this->classify(
                $this->handlerPoint($handler),
                PointCoverage::KIND_MESSENGER_HANDLER,
                $this->catalog->forPoint($message),
                $this->conflictReason($message),
                $this->catalog->ignored()[$message->toString()] ?? null,
                $handler->async ? null : 'sync handling is not measured',
                $handler->async,
            );
        }

        return $points;
    }

    /**
     * @return list<PointCoverage>
     */
    private function logicPoints(CatalogExtras $extras): array
    {
        $measured = isset($extras->metricBuckets[self::LOGIC_HISTOGRAM]);
        $points = [];
        foreach ($extras->taggedPoints as $tagged) {
            $points[] = $this->classify(
                $tagged->point->id,
                PointCoverage::KIND_LOGIC_ACTION,
                $this->catalog->forPoint($tagged->point),
                $this->conflictReason($tagged->point),
                $this->catalog->ignored()[$tagged->point->toString()] ?? null,
                null,
                $measured,
            );
        }

        return $points;
    }

    /**
     * @return list<PointCoverage>
     */
    private function operationPoints(CatalogExtras $extras): array
    {
        $points = [];
        foreach ($extras->operations as $operation) {
            $point = PointId::of(PointType::Operation, $operation);
            $points[] = new PointCoverage(
                $operation,
                PointCoverage::KIND_OPERATION,
                CoverageStatus::Monitored,
                null,
                $this->names($this->catalog->forPoint($point)),
                false,
            );
        }

        return $points;
    }

    /**
     * Precedence: invalid, ignored, unsupported, then monitored or unmonitored.
     *
     * @param non-empty-string $point
     * @param PointCoverage::KIND_* $kind
     * @param list<SloDefinition> $definitions
     * @param non-empty-string|null $invalid
     * @param non-empty-string|null $ignored
     * @param non-empty-string|null $unsupported
     */
    private function classify(string $point, string $kind, array $definitions, ?string $invalid, ?string $ignored, ?string $unsupported, bool $measured): PointCoverage
    {
        $slos = $this->names($definitions);
        [$status, $reason] = match (true) {
            $invalid !== null => [CoverageStatus::Invalid, $invalid],
            $ignored !== null => [CoverageStatus::Ignored, $ignored],
            $unsupported !== null => [CoverageStatus::Unsupported, $unsupported],
            $slos !== [] => [CoverageStatus::Monitored, null],
            default => [CoverageStatus::Unmonitored, 'no SLO is declared'],
        };

        return new PointCoverage($point, $kind, $status, $reason, $slos, $measured);
    }

    /**
     * @return non-empty-string|null
     */
    private function conflictReason(PointId $point): ?string
    {
        $names = $this->catalog->conflictedNamesFor($point);

        return $names === [] ? null : \sprintf('SLO %s is declared differently on several points', implode(', ', $names));
    }

    /**
     * @param list<SloDefinition> $definitions
     *
     * @return list<non-empty-string>
     */
    private function names(array $definitions): array
    {
        $names = array_map(static fn (SloDefinition $d): string => $d->name()->value, $definitions);
        sort($names, SORT_STRING);

        return array_values(array_unique($names));
    }

    /**
     * @return non-empty-string
     */
    private function handlerPoint(HandlerInfo $handler): string
    {
        return $handler->handlerClass . '::' . $handler->method;
    }

    private function percent(int $part, int $whole): float
    {
        return $whole <= 0 ? 100.0 : round($part / $whole * 100, 2);
    }
}
