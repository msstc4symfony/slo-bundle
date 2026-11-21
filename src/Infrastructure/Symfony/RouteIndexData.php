<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Symfony;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogDump;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Override;
use Throwable;

/**
 * Routes, the route → SLO points index and the precomputed objective revisions in the plain-array form that
 * RouteIndexFile stores. Numeric route names ("404") become int keys in PHP arrays: keys are cast back to strings on
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

    private const REVISION_KEY = '/^[a-z][a-z0-9_.-]{0,62}\|(availability|latency|combined)$/D';

    private const REVISION_VALUE = '/^(?:[0-9a-f]{8})?$/D';

    /**
     * @param array<array-key, RouteShape> $routes sorted by name
     * @param array<non-empty-string, non-empty-list<non-empty-string>> $controllers controller => route names, sorted
     * @param array<array-key, non-empty-list<PointId>> $points route name => SLO points behind it
     * @param array<non-empty-string, string> $revisions "<slo>|<objective>" => revision of a dedicated objective, '' when it reads existing series
     */
    private function __construct(
        private array $routes,
        private array $controllers,
        private array $points,
        private array $revisions,
    ) {
    }

    /**
     * @param list<RouteInfo> $routes
     * @param array<array-key, list<PointId>> $points route name => SLO points behind it
     * @param array<non-empty-string, string> $revisions "<slo>|<objective>" => revision or ''
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
     * @return self|null null when the value is not a well-formed index of the current version
     */
    public static function fromArray(mixed $value): ?self
    {
        if (!self::isCurrentVersion($value)) {
            return null;
        }
        $routes = self::routesFromArray($value['routes'] ?? null);
        $controllers = self::controllersFromArray($value['controllers'] ?? null);
        $points = self::pointsFromArray($value);
        $revisions = self::revisionsFromArray($value);
        if ($routes === null || $controllers === null || $points === null || $revisions === null) {
            return null;
        }
        foreach ($controllers as $names) {
            foreach ($names as $name) {
                if (!isset($routes[$name])) {
                    return null;
                }
            }
        }

        return new self($routes, $controllers, $points, $revisions);
    }

    /**
     * Validates the points section only: the hot path needs nothing else.
     *
     * @return array<array-key, non-empty-list<PointId>>|null
     */
    public static function pointsFromArray(mixed $value): ?array
    {
        if (!self::isCurrentVersion($value)) {
            return null;
        }
        $map = self::stringListMap($value['points'] ?? null);
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
     * Validates the revisions section only.
     *
     * @return array<non-empty-string, string>|null
     */
    public static function revisionsFromArray(mixed $value): ?array
    {
        if (!self::isCurrentVersion($value) || !\is_array($value['revisions'] ?? null)) {
            return null;
        }
        $revisions = [];
        foreach ($value['revisions'] as $key => $revision) {
            if (!\is_string($key) || preg_match(self::REVISION_KEY, $key) !== 1 || !\is_string($revision) || preg_match(self::REVISION_VALUE, $revision) !== 1) {
                return null;
            }
            $revisions[$key] = $revision;
        }

        return $revisions;
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
     * @phpstan-assert-if-true array<array-key, mixed> $value
     */
    private static function isCurrentVersion(mixed $value): bool
    {
        return \is_array($value) && ($value['version'] ?? null) === self::VERSION;
    }

    /**
     * @return non-empty-string
     */
    private static function name(int|string $key): string
    {
        $name = (string) $key;

        return $name === '' ? throw new InvalidArgumentException('Route name must not be empty.') : $name;
    }

    /**
     * @return array<array-key, RouteShape>|null
     */
    private static function routesFromArray(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }
        $routes = [];
        foreach ($value as $name => $route) {
            if ($name === '' || !\is_array($route)) {
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
    private static function controllersFromArray(mixed $value): ?array
    {
        $map = self::stringListMap($value);
        if ($map === null) {
            return null;
        }
        $controllers = [];
        foreach ($map as $controller => $names) {
            if (!\is_string($controller) || $controller === '') {
                return null;
            }
            $controllers[$controller] = $names;
        }

        return $controllers;
    }

    /**
     * @return array<array-key, non-empty-list<non-empty-string>>|null
     */
    private static function stringListMap(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }
        $map = [];
        foreach ($value as $key => $list) {
            if ($key === '' || !self::isStringList($list)) {
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
     * @phpstan-assert-if-true list<string> $value
     */
    private static function isStringList(mixed $value): bool
    {
        return \is_array($value) && array_is_list($value) && array_filter($value, \is_string(...)) === $value;
    }
}
