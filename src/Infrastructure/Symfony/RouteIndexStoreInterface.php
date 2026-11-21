<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use RuntimeException;

/**
 * Where SloCacheWarmer keeps the route index for the request path. Reads never throw: a missing or malformed index
 * (or entry) reads as null and the callers fall back to the router. The per-key reads validate only the entry they
 * return, so the request path never pays for the whole index.
 *
 * @internal
 */
interface RouteIndexStoreInterface
{
    /**
     * The whole index, validated (CLI).
     */
    public function load(): ?RouteIndexData;

    /**
     * @return list<PointId>|null the SLO points behind the route, [] for a route without any, null when not warmed
     */
    public function pointsFor(string $route): ?array;

    /**
     * @param non-empty-string $key "<slo>|<objective>"
     *
     * @return Revision|false|null the revision of a dedicated objective, false when it reads existing series, null when
     *                             nothing usable was stored for the key
     */
    public function revisionOf(string $key): Revision|false|null;

    /**
     * @param non-empty-string $controller
     *
     * @return list<RouteInfo>|null the routes of the controller sorted by name, null when not warmed
     */
    public function routesFor(string $controller): ?array;

    /**
     * @param string $cacheDir the directory being warmed, which may differ from the one the store reads
     *
     * @throws RuntimeException when the index cannot be stored
     */
    public function write(string $cacheDir, RouteIndexData $data): void;
}
