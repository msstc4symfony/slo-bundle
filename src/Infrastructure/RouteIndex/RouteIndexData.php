<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\RouteIndex;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Override;

/**
 * Routes, the route → SLO points index and the precomputed objective revisions; toArray() is the plain-array form the
 * RouteIndexStoreInterface keeps, RouteIndexParser reads it back. Numeric route names ("404") become int keys in PHP arrays: keys are cast back to strings on
 * every read.
 *
 * @phpstan-type RouteShape array{path: string, methods: list<string>, controller: non-empty-string|null}
 * @phpstan-type RouteIndexShape array{
 *     version: int,
 *     routes: array<array-key, RouteShape>,
 *     controllers: array<non-empty-string, non-empty-list<non-empty-string>>,
 *     points: array<array-key, non-empty-list<non-empty-string>>,
 *     revisions: array<non-empty-string, string>,
 * }
 *
 * @internal
 */
final readonly class RouteIndexData implements RouteCatalogInterface
{
    public const VERSION = 2;

    /**
     * @param array<array-key, RouteShape> $routes sorted by name
     * @param array<non-empty-string, non-empty-list<non-empty-string>> $controllers controller => route names, sorted
     * @param array<array-key, non-empty-list<PointId>> $points route name => SLO points behind it
     * @param array<non-empty-string, string> $revisions ObjectiveKey value ("<slo>|<objective>") => revision of a dedicated objective, '' when it reads existing series
     */
    public function __construct(
        private array $routes,
        private array $controllers,
        private array $points,
        private array $revisions,
    ) {
        foreach ($controllers as $controller => $names) {
            foreach ($names as $name) {
                if (!isset($routes[$name])) {
                    throw new InvalidArgumentException(\sprintf('Controller "%s" points to the unknown route "%s".', $controller, $name));
                }
            }
        }
    }

    /**
     * @param list<RouteInfo> $routes
     * @param array<array-key, list<PointId>> $points route name => SLO points behind it
     * @param array<non-empty-string, string> $revisions ObjectiveKey value ("<slo>|<objective>") => revision or ''
     */
    public static function fromRoutes(array $routes, array $points, array $revisions = []): self
    {
        $shapes = [];
        $controllers = [];
        foreach ($routes as $route) {
            $shapes[$route->name] = ['path' => $route->path, 'methods' => $route->methods, 'controller' => $route->controller];
        }
        ksort($shapes, SORT_STRING);
        foreach ($shapes as $name => $shape) {
            if ($shape['controller'] !== null) {
                $controllers[$shape['controller']][] = self::name($name);
            }
        }
        ksort($points, SORT_STRING);
        ksort($revisions, SORT_STRING);

        return new self($shapes, $controllers, array_filter($points, static fn (array $list): bool => $list !== []), $revisions);
    }

    /**
     * HTTP points behind each route that carry an SLO or a conflicting SLO name.
     *
     * @param list<RouteInfo> $routes
     *
     * @return array<array-key, non-empty-list<PointId>>
     */
    public static function pointsOf(array $routes, SloCatalogInterface $catalog): array
    {
        $index = [];
        foreach ($routes as $route) {
            if ($route->controller === null) {
                continue;
            }
            $point = PointId::of(PointType::HttpRoute, $route->controller);
            if ($catalog->forPoint($point) !== [] || $catalog->conflictedNamesFor($point) !== []) {
                $index[$route->name][] = $point;
            }
        }
        ksort($index, SORT_STRING);

        return $index;
    }

    /**
     * @return RouteIndexShape
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'routes' => $this->routes,
            'controllers' => $this->controllers,
            'points' => array_map(
                static fn (array $points): array => array_map(static fn (PointId $point): string => $point->toString(), $points),
                $this->points,
            ),
            'revisions' => $this->revisions,
        ];
    }

    /**
     * @return list<RouteInfo> sorted by route name
     */
    #[Override]
    public function all(): array
    {
        $all = [];
        foreach ($this->routes as $name => $route) {
            $all[] = new RouteInfo(self::name($name), $route['path'], $route['methods'], $route['controller']);
        }

        return $all;
    }

    /**
     * @return list<RouteInfo> sorted by route name
     */
    #[Override]
    public function routesFor(string $controller): array
    {
        $routes = [];
        foreach ($this->controllers[$controller] ?? [] as $name) {
            $route = $this->routes[$name];
            $routes[] = new RouteInfo($name, $route['path'], $route['methods'], $route['controller']);
        }

        return $routes;
    }

    /**
     * @return list<PointId>
     */
    public function pointsFor(string $route): array
    {
        return $this->points[$route] ?? [];
    }

    /**
     * @return array<array-key, list<PointId>>
     */
    public function points(): array
    {
        return $this->points;
    }

    /**
     * @return array<non-empty-string, string>
     */
    public function revisions(): array
    {
        return $this->revisions;
    }

    /**
     * @return non-empty-string
     */
    private static function name(int|string $key): string
    {
        $name = (string) $key;

        return $name === '' ? throw new InvalidArgumentException('Route name must not be empty.') : $name;
    }
}
