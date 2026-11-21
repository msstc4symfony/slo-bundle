<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\RouteIndex;

use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Binding\ObjectiveKey;
use Msstc4Symfony\SloBundle\Application\Binding\RevisionResolver;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the route index from a set of routes: the SLO points behind each route and the objective revisions, both
 * resolved against these routes (not the router service's). Used by the cache warmer and by the request path when no
 * warmed index exists.
 *
 * @internal
 */
final readonly class RouteIndexBuilder
{
    public function __construct(
        private SloCatalogInterface $catalog,
        private MetricBindingResolver $resolver,
        private RevisionResolver $revisions,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * An objective that fails to resolve, or a catalog that cannot list its definitions, is left out of the revisions
     * (resolved on first use) and logged.
     *
     * @param list<RouteInfo> $routes
     *
     * @throws Throwable when the points behind the routes cannot be read from the catalog
     */
    public function build(array $routes): RouteIndexData
    {
        $points = RouteIndexData::pointsOf($routes, $this->catalog);
        try {
            $revisions = $this->revisionsOf(RouteIndexData::fromRoutes($routes, $points));
        } catch (Throwable $e) {
            $revisions = [];
            $this->logger->warning('SLO objective revisions could not be precomputed for the route index, they are resolved on first use.', ['exception' => $e]);
        }

        return RouteIndexData::fromRoutes($routes, $points, $revisions);
    }

    /**
     * @return array<non-empty-string, string>
     */
    private function revisionsOf(RouteIndexData $routes): array
    {
        $resolver = $this->resolver->withRoutes($routes);
        $revisions = [];
        foreach ($this->catalog->all() as $definition) {
            foreach ($definition->objectives() as $objective) {
                try {
                    $revisions[ObjectiveKey::of($definition->name(), $objective->kind)->value] = $resolver->isDedicated($definition, $objective->kind)
                        ? $this->revisions->revision($definition, $objective->kind)->value
                        : '';
                } catch (Throwable $e) {
                    $this->logger->warning('SLO objective could not be resolved for the route index, it is resolved on first use.', ['slo' => $definition->name()->value, 'objective' => $objective->kind->value, 'exception' => $e]);
                }
            }
        }

        return $revisions;
    }
}
