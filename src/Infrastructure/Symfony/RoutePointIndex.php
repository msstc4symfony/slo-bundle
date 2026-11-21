<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Symfony;

use Monolog\Attribute\WithMonologChannel;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Application\Port\RoutePointIndexInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Built lazily on first use and memoised for the process, routes are fixed per container (ruling 7). A failed build
 * is memoised as an empty index: logged once, HTTP SLOs then record nothing.
 */
#[WithMonologChannel('slo_bundle')]
final class RoutePointIndex implements RoutePointIndexInterface
{
    /** @var array<non-empty-string, list<PointId>>|null */
    private ?array $index = null;

    public function __construct(
        private readonly RouteCatalogInterface $routes,
        private readonly SloCatalogInterface $catalog,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function pointsFor(string $route): array
    {
        $this->index ??= $this->build();

        return $this->index[$route] ?? [];
    }

    /**
     * @return array<non-empty-string, list<PointId>>
     */
    private function build(): array
    {
        try {
            $index = [];
            foreach ($this->routes->all() as $route) {
                if ($route->controller === null) {
                    continue;
                }
                $point = PointId::of(PointType::HttpRoute, $route->controller);
                if ($this->catalog->forPoint($point) !== [] || $this->catalog->conflictedNamesFor($point) !== []) {
                    $index[$route->name][] = $point;
                }
            }

            return $index;
        } catch (Throwable $e) {
            try {
                $this->logger->warning('SLO route index could not be built, HTTP SLO events are not recorded.', ['exception' => $e]);
            } catch (Throwable) {
                // A failing logger must not break fail-open recording.
            }

            return [];
        }
    }
}
