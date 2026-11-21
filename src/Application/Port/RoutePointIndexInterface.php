<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;

interface RoutePointIndexInterface
{
    /**
     * @param non-empty-string $route
     *
     * @return list<PointId> HTTP points behind the route that carry an SLO (or a conflicting SLO name), empty otherwise
     */
    public function pointsFor(string $route): array;
}
