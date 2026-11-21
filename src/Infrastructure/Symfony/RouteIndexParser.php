<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Symfony;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CatalogDump;
use Throwable;

/**
 * Validates a stored route index (RouteIndexData::toArray() form) without trusting it: every method returns null
 * instead of throwing on a malformed value. parse() validates everything; the *Entry() reads validate one entry of a
 * value that passed isCurrentVersion().
 *
 * @phpstan-import-type RouteShape from RouteIndexData
 *
 * @internal
 */
final class RouteIndexParser
{
    private const REVISION_KEY = '/^[a-z][a-z0-9_.-]{0,62}\|(availability|latency|combined)$/D';

    private const REVISION_VALUE = '/^(?:[0-9a-f]{8})?$/D';

    /**
     * @return RouteIndexData|null null when the value is not a well-formed index of the current version
     */
    public static function parse(mixed $value): ?RouteIndexData
    {
        if (!self::isCurrentVersion($value)) {
            return null;
        }
        $routes = self::routesFromArray($value['routes'] ?? null);
        $controllers = self::controllersFromArray($value['controllers'] ?? null);
        $points = self::points($value);
        $revisions = self::revisions($value);
        if ($routes === null || $controllers === null || $points === null || $revisions === null) {
            return null;
        }
        try {
            return new RouteIndexData($routes, $controllers, $points, $revisions);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @phpstan-assert-if-true array<array-key, mixed> $value
     */
    public static function isCurrentVersion(mixed $value): bool
    {
        return \is_array($value) && ($value['version'] ?? null) === RouteIndexData::VERSION;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return list<PointId>|null empty for a route without SLO points, null when the entry is malformed
     */
    public static function pointsEntry(array $value, string $route): ?array
    {
        $points = $value['points'] ?? null;
        if (!\is_array($points)) {
            return null;
        }
        $entry = $points[$route] ?? [];
        if (!self::isStringList($entry)) {
            return null;
        }
        $points = [];
        try {
            foreach ($entry as $point) {
                if ($point === '') {
                    return null;
                }
                $points[] = CatalogDump::point($point);
            }
        } catch (Throwable) {
            return null;
        }

        return $points;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return Revision|false|null false for an objective reading existing series, null when absent or malformed
     */
    public static function revisionEntry(array $value, string $key): Revision|false|null
    {
        $revisions = $value['revisions'] ?? null;
        $revision = \is_array($revisions) ? $revisions[$key] ?? null : null;
        if ($revision === '') {
            return false;
        }
        if (!\is_string($revision)) {
            return null;
        }
        try {
            return Revision::fromHex($revision);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return list<RouteInfo>|null sorted by route name, null when the controller's entry or one of its routes is malformed
     */
    public static function routesEntry(array $value, string $controller): ?array
    {
        $controllers = $value['controllers'] ?? null;
        $routes = $value['routes'] ?? null;
        if (!\is_array($controllers) || !\is_array($routes)) {
            return null;
        }
        $names = $controllers[$controller] ?? [];
        if (!self::isStringList($names)) {
            return null;
        }
        $infos = [];
        foreach ($names as $name) {
            $route = $name === '' ? null : self::route($routes[$name] ?? null);
            if ($route === null) {
                return null;
            }
            $infos[] = new RouteInfo($name, $route['path'], $route['methods'], $route['controller']);
        }

        return $infos;
    }

    /**
     * @return array<array-key, non-empty-list<PointId>>|null
     */
    private static function points(mixed $value): ?array
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
     * @return array<non-empty-string, string>|null
     */
    private static function revisions(mixed $value): ?array
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
     * @return array<array-key, RouteShape>|null
     */
    private static function routesFromArray(mixed $value): ?array
    {
        if (!\is_array($value)) {
            return null;
        }
        $routes = [];
        foreach ($value as $name => $route) {
            $shape = $name === '' ? null : self::route($route);
            if ($shape === null) {
                return null;
            }
            $routes[$name] = $shape;
        }

        return $routes;
    }

    /**
     * @return RouteShape|null
     */
    private static function route(mixed $route): ?array
    {
        if (!\is_array($route)) {
            return null;
        }
        $path = $route['path'] ?? null;
        $methods = $route['methods'] ?? null;
        $controller = $route['controller'] ?? null;
        if (!\is_string($path) || !self::isStringList($methods) || ($controller !== null && (!\is_string($controller) || $controller === ''))) {
            return null;
        }

        return ['path' => $path, 'methods' => $methods, 'controller' => $controller];
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
