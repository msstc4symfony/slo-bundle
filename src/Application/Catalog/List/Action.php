<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Catalog\List;

use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;

/**
 * @internal
 */
final readonly class Action
{
    public function __construct(
        private SloCatalogInterface $catalog,
        private CatalogDiagnosticsInterface $diagnostics,
        private RouteCatalogInterface $routes,
        private RevisionCalculator $revisions,
        private MetricBindingResolver $bindings,
    ) {
    }

    public function __invoke(): Output
    {
        $rows = [];
        foreach ($this->catalog->all() as $definition) {
            $points = $definition->points();
            $rows[] = $this->row($definition, Row::STATUS_OK, \count($points) > 1 ? $this->pointStrings($points) : [], true);
        }
        foreach ($this->diagnostics->conflicts() as $declarations) {
            foreach ($declarations as $declaration) {
                $rows[] = $this->row($declaration->definition, Row::STATUS_DUPLICATE, [], false);
            }
        }

        usort($rows, static fn (Row $a, Row $b): int => [$a->name, $a->points[0]->point] <=> [$b->name, $b->points[0]->point]);

        return new Output($rows);
    }

    /**
     * @param Row::STATUS_* $status
     * @param list<non-empty-string> $sharedPoints
     * @param bool $served false for conflicting declarations, which record nothing and so have no strategy
     */
    private function row(SloDefinition $definition, string $status, array $sharedPoints, bool $served): Row
    {
        $objectives = array_map(
            fn (Objective $objective): ObjectiveRow => new ObjectiveRow(
                $objective->kind->value,
                $objective->target->percent,
                $objective->threshold?->toString(),
                $this->revisions->revision($definition, $objective->kind)->value,
                $served ? $this->bindings->resolve($definition, $objective)->strategy->value : null,
            ),
            $definition->objectives(),
        );
        usort($objectives, static fn (ObjectiveRow $a, ObjectiveRow $b): int => strcmp($a->kind, $b->kind));

        $points = array_map(fn (PointId $point): PointRow => new PointRow($point->toString(), $this->routeNames($point)), $definition->points());
        usort($points, static fn (PointRow $a, PointRow $b): int => strcmp($a->point, $b->point));

        return new Row(
            $definition->name()->value,
            $status,
            $definition->pointType()->value,
            $definition->window()->toString(),
            $objectives,
            $points,
            $sharedPoints,
        );
    }

    /**
     * @return list<non-empty-string>
     */
    private function routeNames(PointId $point): array
    {
        if ($point->type !== PointType::HttpRoute) {
            return [];
        }

        $names = array_map(static fn (RouteInfo $route): string => $route->name, $this->routes->routesFor($point->id));
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * @param non-empty-list<PointId> $points
     *
     * @return list<non-empty-string>
     */
    private function pointStrings(array $points): array
    {
        $strings = array_map(static fn (PointId $point): string => $point->toString(), $points);
        sort($strings, SORT_STRING);

        return $strings;
    }
}
