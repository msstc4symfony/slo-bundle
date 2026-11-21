<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Symfony;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogDump;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Throwable;

/**
 * Validates a stored route index (RouteIndexData::toArray() form) without trusting it: every method returns null
 * instead of throwing on a malformed value.
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
     * Validates the points section only: the hot path needs nothing else.
     *
     * @return array<array-key, non-empty-list<PointId>>|null
     */
    public static function points(mixed $value): ?array
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
    public static function revisions(mixed $value): ?array
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
     * @phpstan-assert-if-true array<array-key, mixed> $value
     */
    private static function isCurrentVersion(mixed $value): bool
    {
        return \is_array($value) && ($value['version'] ?? null) === RouteIndexData::VERSION;
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
