<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use RuntimeException;

/**
 * Where SloCacheWarmer keeps the route index for the request path. Reads never throw: a missing or malformed index
 * reads as null and the callers fall back to the router.
 *
 * @internal
 */
interface RouteIndexStoreInterface
{
    /**
     * The whole index, validated.
     */
    public function load(): ?RouteIndexData;

    /**
     * Route name => SLO points, validating only that section (hot path).
     *
     * @return array<array-key, non-empty-list<PointId>>|null
     */
    public function points(): ?array;

    /**
     * "<slo>|<objective>" => revision of a dedicated objective or '' (hot path).
     *
     * @return array<non-empty-string, string>|null
     */
    public function revisions(): ?array;

    /**
     * @param string $cacheDir the directory being warmed, which may differ from the one the store reads
     *
     * @throws RuntimeException when the index cannot be stored
     */
    public function write(string $cacheDir, RouteIndexData $data): void;
}
