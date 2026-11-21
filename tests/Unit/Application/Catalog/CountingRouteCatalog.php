<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog;

use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Override;
use RuntimeException;

/**
 * Counts controller lookups; the first $failures lookups throw like a broken router.
 */
final class CountingRouteCatalog implements RouteCatalogInterface
{
    public int $lookups = 0;

    /**
     * @param list<RouteInfo> $routes
     * @param non-negative-int $failures
     */
    public function __construct(
        private readonly array $routes,
        private int $failures = 0,
    ) {
    }

    #[Override]
    public function routesFor(string $controller): array
    {
        $this->lookups++;
        if ($this->failures > 0) {
            $this->failures--;

            throw new RuntimeException('router broken');
        }

        return array_values(array_filter($this->routes, static fn (RouteInfo $route): bool => $route->controller === $controller));
    }

    #[Override]
    public function all(): array
    {
        return $this->routes;
    }
}
