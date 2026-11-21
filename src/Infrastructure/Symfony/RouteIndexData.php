<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Application\Discovery\CatalogDump;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Throwable;

/**
 * Routes and the route → SLO points index in a plain-array form that RouteIndexFile stores.
 *
 * @phpstan-type RouteShape array{path: string, methods: list<string>, controller: non-empty-string|null}
 * @phpstan-type RouteIndexShape array{
 *     version: int,
 *     routes: array<non-empty-string, RouteShape>,
 *     controllers: array<non-empty-string, non-empty-list<non-empty-string>>,
 *     points: array<non-empty-string, non-empty-list<non-empty-string>>,
 * }
 *
 * @internal
 */
final readonly class RouteIndexData
{
    public const VERSION = 1;

    /**
     * @param array<non-empty-string, RouteShape> $routes sorted by name
     * @param array<non-empty-string, non-empty-list<non-empty-string>> $controllers controller => route names, sorted
     * @param array<non-empty-string, non-empty-list<PointId>> $points route name => SLO points behind it
     */
    private function __construct(
        private array $routes,
        private array $controllers,
        private array $points,
    ) {
    }

    /**
     * @param list<RouteInfo> $routes
     * @param array<non-empty-string, list<PointId>> $points route name => SLO points behind it
     */
    public static function fromRoutes(array $routes, array $points): self
    {
        $shapes = [];
        $controllers = [];
        foreach ($routes as $route) {
            $shapes[$route->name] = ['path' => $route->path, 'methods' => $route->methods, 'controller' => $route->controller];
        }
        ksort($shapes, SORT_STRING);
        foreach ($shapes as $name => $shape) {
            if ($shape['controller'] !== null) {
                $controllers[$shape['controller']][] = $name;
            }
        }
        ksort($points, SORT_STRING);

        return new self($shapes, $controllers, array_filter($points, static fn (array $list): bool => $list !== []));
    }

    /**
     * HTTP points behind each route that carry an SLO or a conflicting SLO name.
     *
     * @param list<RouteInfo> $routes
     *
     * @return array<non-empty-string, non-empty-list<PointId>>
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
     * @return self|null null when the value is not a well-formed index of the current version
     */
    public static function fromArray(mixed $value): ?self
    {
        if (!\is_array($value) || ($value['version'] ?? null) !== self::VERSION) {
            return null;
        }
        $routes = self::routesFromArray($value['routes'] ?? null);
        $controllers = self::stringListMap($value['controllers'] ?? null);
        $points = self::pointsFromArray($value['points'] ?? null);
        if ($routes === null || $controllers === null || $points === null) {
            return null;
        }
        foreach ($controllers as $names) {
            foreach ($names as $name) {
                if (!isset($routes[$name])) {
                    return null;
                }
            }
        }

        return new self($routes, $controllers, $points);
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
        ];
    }

    /**
     * @return list<RouteInfo> sorted by route name
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->routes as $name => $route) {
            $all[] = new RouteInfo($name, $route['path'], $route['methods'], $route['controller']);
        }

        return $all;
    }

    /**
     * @return list<RouteInfo> sorted by route name
     */
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
     * @return array<non-empty-string, list<PointId>>
     */
    public function points(): array
    {
        return $this->points;
    }

    /**
     * @return array<non-empty-string, RouteShape>|null
     */
    private static function routesFromArray(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }
        $routes = [];
        foreach ($value as $name => $route) {
            if (!\is_string($name) || $name === '' || !\is_array($route)) {
                return null;
            }
            $path = $route['path'] ?? null;
            $methods = $route['methods'] ?? null;
            $controller = $route['controller'] ?? null;
            if (!\is_string($path) || !self::isStringList($methods) || ($controller !== null && (!\is_string($controller) || $controller === ''))) {
                return null;
            }
            $routes[$name] = ['path' => $path, 'methods' => $methods, 'controller' => $controller];
        }

        return $routes;
    }

    /**
     * @return array<non-empty-string, non-empty-list<non-empty-string>>|null
     */
    private static function stringListMap(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }
        $map = [];
        foreach ($value as $key => $list) {
            if (!\is_string($key) || $key === '' || !self::isStringList($list)) {
                return null;
            }
            $items = array_values(array_filter($list, static fn (string $item): bool => $item !== ''));
            if ($items === [] || \count($items) !== \count($list)) {
                return null;
            }
            $map[$key] = $items;
        }

        return $map;
    }

    /**
     * @return array<non-empty-string, non-empty-list<PointId>>|null
     */
    private static function pointsFromArray(mixed $value): ?array
    {
        $map = self::stringListMap($value);
        if ($map === null) {
            return null;
        }
        try {
            return array_map(static fn (array $points): array => array_map(CatalogDump::point(...), $points), $map);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @phpstan-assert-if-true list<string> $value
     */
    private static function isStringList(mixed $value): bool
    {
        return \is_array($value) && array_is_list($value) && array_filter($value, \is_string(...)) === $value;
    }
}
