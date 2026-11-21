<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\RouteIndex;

use Closure;
use Msstc4Symfony\SloBundle\Application\Binding\ObjectiveKey;
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
     * @return Revision|false|null the revision of a dedicated objective, false when it reads existing series, null when
     *                             nothing usable was stored for the key
     */
    public function revisionOf(ObjectiveKey $key): Revision|false|null;

    /**
     * @param non-empty-string $controller
     *
     * @return list<RouteInfo>|null the routes of the controller sorted by name, null when not warmed
     */
    public function routesFor(string $controller): ?array;

    /**
     * Stores an index the request path had to build itself where this store reads it, so the following processes do
     * not read the router again. Does nothing in debug mode or when a current index was read. Never throws: a cache
     * directory that cannot be written is skipped.
     *
     * @param Closure(): RouteIndexData $build called only when the index is going to be stored
     */
    public function persist(Closure $build): void;

    /**
     * @param string $cacheDir the directory being warmed, which may differ from the one the store reads
     *
     * @throws RuntimeException when the index cannot be stored
     */
    public function write(string $cacheDir, RouteIndexData $data): void;
}
