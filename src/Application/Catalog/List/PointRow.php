<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Catalog\List;

final readonly class PointRow
{
    /**
     * @param non-empty-string $point
     * @param list<non-empty-string> $routes route names for HTTP points, sorted
     */
    public function __construct(
        public string $point,
        public array $routes,
    ) {
    }
}
