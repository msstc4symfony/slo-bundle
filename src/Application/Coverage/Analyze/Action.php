<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Coverage\Analyze;

use Msstc4Symfony\SloBundle\Application\Binding\DurationBuckets;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Application\Port\SeriesCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;

/**
 * Static coverage of the application by SLOs, spec 5.7. Reads only compile-time facts, never a time series.
 *
 * @internal
 */
final readonly class Action
{
    private const IGNORED_BY_METRICS_BUNDLE = 'route is in the ignored routes of metrics-bundle (http.ignored_routes), it is never measured';

    private const SYSTEM_ROUTE = 'system route';

    private const IGNORED_BY_COVERAGE_CONFIG = 'configured in coverage.http_routes.ignored_routes';

    private const SYNC_HANDLER = 'sync handling is not measured';

    private const CONFLICT = 'SLO %s is declared differently on several points';

    public function __construct(
        private SloCatalogInterface $catalog,
        private CatalogDiagnosticsInterface $diagnostics,
        private RouteCatalogInterface $routes,
        private SeriesCatalogInterface $series,
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
            ...$this->scheduledTaskPoints($extras),
        ];
        usort($points, PointCoverage::compare(...));

        $kinds = array_map(fn (CoverageKind $kind): KindCoverage => $this->summarise($kind, $points), CoverageKind::cases());
        $invalid = \count(array_filter($points, static fn (PointCoverage $point): bool => $point->status === CoverageStatus::Invalid));

        return new Output($points, $kinds, $invalid);
    }

    /**
     * @param list<PointCoverage> $points
     */
    private function summarise(CoverageKind $kind, array $points): KindCoverage
    {
        $total = 0;
        $monitored = 0;
        $excluded = 0;
        $measured = 0;
        foreach ($points as $point) {
            if ($point->kind !== $kind) {
                continue;
            }
            $total++;
            $monitored += (int) ($point->status === CoverageStatus::Monitored);
            $excluded += (int) ($point->status === CoverageStatus::Ignored || $point->status === CoverageStatus::Unsupported);
            $measured += (int) $point->measuredByUniversalMetric;
        }

        return new KindCoverage($kind, $monitored, max(0, $total - $excluded), $total === 0 ? 100.0 : round($measured / $total * 100, 2));
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
            $ignoredByMetricsBundle = \in_array($route->name, $extras->httpIgnoredRoutes, true);
            $systemRoute = $ignoredByMetricsBundle || str_starts_with($route->name, '_');

            $verdict = CoverageVerdict::decide(
                covered: $definitions !== [],
                invalid: $this->httpInvalidReason($point, $definitions !== [], $ignoredByMetricsBundle),
                ignored: $this->httpIgnoreReason($route, $point, $systemRoute, $input),
            );
            $points[] = PointCoverage::judged($route->name, CoverageKind::HttpRoute, $verdict, $this->names($definitions), !$systemRoute);
        }

        return $points;
    }

    /**
     * @return non-empty-string|null
     */
    private function httpInvalidReason(?PointId $point, bool $covered, bool $ignoredByMetricsBundle): ?string
    {
        $conflict = $point instanceof PointId ? $this->conflictReason($point) : null;
        if ($conflict !== null) {
            return $conflict;
        }

        return $covered && $ignoredByMetricsBundle ? self::IGNORED_BY_METRICS_BUNDLE : null;
    }

    /**
     * @return non-empty-string|null
     */
    private function httpIgnoreReason(RouteInfo $route, ?PointId $point, bool $systemRoute, Input $input): ?string
    {
        return match (true) {
            $systemRoute => self::SYSTEM_ROUTE,
            \in_array($route->name, $input->ignoredRoutes, true) => self::IGNORED_BY_COVERAGE_CONFIG,
            default => $point instanceof PointId ? $this->ignoreReason($point) : null,
        };
    }

    /**
     * @return list<PointCoverage>
     */
    private function messengerPoints(CatalogExtras $extras): array
    {
        $points = [];
        foreach ($extras->handlers as $handler) {
            $message = PointId::of(PointType::MessengerMessage, $handler->messageClass);
            $definitions = $this->catalog->forPoint($message);

            $verdict = CoverageVerdict::decide(
                covered: $definitions !== [],
                invalid: $this->conflictReason($message),
                ignored: $this->ignoreReason($message),
                unsupported: $handler->async ? null : self::SYNC_HANDLER,
            );
            $points[] = PointCoverage::judged($handler->handlerClass . '::' . $handler->method, CoverageKind::MessengerHandler, $verdict, $this->names($definitions), $handler->async);
        }

        return $points;
    }

    /**
     * @return list<PointCoverage>
     */
    private function logicPoints(CatalogExtras $extras): array
    {
        $measured = DurationBuckets::of($this->series, $this->diagnostics, PointType::LogicAction) !== null;
        $points = [];
        foreach ($extras->taggedPoints as $tagged) {
            $definitions = $this->catalog->forPoint($tagged->point);

            $verdict = CoverageVerdict::decide(
                covered: $definitions !== [],
                invalid: $this->conflictReason($tagged->point),
                ignored: $this->ignoreReason($tagged->point),
            );
            $points[] = PointCoverage::judged($tagged->point->id, CoverageKind::LogicAction, $verdict, $this->names($definitions), $measured);
        }

        return $points;
    }

    /**
     * Declared in the configuration, so always monitored.
     *
     * @return list<PointCoverage>
     */
    private function operationPoints(CatalogExtras $extras): array
    {
        $points = [];
        foreach ($extras->operations as $operation) {
            $definitions = $this->catalog->forPoint(PointId::of(PointType::Operation, $operation));
            $points[] = PointCoverage::judged($operation, CoverageKind::Operation, CoverageVerdict::decide(covered: true), $this->names($definitions), false);
        }

        return $points;
    }

    /**
     * No universal metric records Scheduler runs, so a task is never measured without an SLO.
     *
     * @return list<PointCoverage>
     */
    private function scheduledTaskPoints(CatalogExtras $extras): array
    {
        $points = [];
        foreach ($extras->scheduledTasks as $task) {
            $point = PointId::of(PointType::ScheduledTask, $task);
            $definitions = $this->catalog->forPoint($point);

            $verdict = CoverageVerdict::decide(
                covered: $definitions !== [],
                invalid: $this->conflictReason($point),
                ignored: $this->ignoreReason($point),
            );
            $points[] = PointCoverage::judged($task, CoverageKind::ScheduledTask, $verdict, $this->names($definitions), false);
        }

        return $points;
    }

    /**
     * @return non-empty-string|null
     */
    private function conflictReason(PointId $point): ?string
    {
        $names = $this->catalog->conflictedNamesFor($point);

        return $names === [] ? null : \sprintf(self::CONFLICT, implode(', ', $names));
    }

    /**
     * @return non-empty-string|null
     */
    private function ignoreReason(PointId $point): ?string
    {
        return $this->catalog->ignored()[$point->toString()] ?? null;
    }

    /**
     * @param list<SloDefinition> $definitions
     *
     * @return list<non-empty-string>
     */
    private function names(array $definitions): array
    {
        $names = array_map(static fn (SloDefinition $definition): string => $definition->name()->value, $definitions);
        sort($names, SORT_STRING);

        return array_values(array_unique($names));
    }
}
