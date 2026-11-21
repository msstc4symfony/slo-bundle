<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog;

use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Override;

final readonly class InMemoryRouteCatalog implements RouteCatalogInterface
{
    /**
     * @param list<RouteInfo> $routes
     */
    public function __construct(private array $routes = [])
    {
    }

    #[Override]
    public function routesFor(string $controller): array
    {
        return array_values(array_filter($this->routes, static fn (RouteInfo $route): bool => $route->controller === $controller));
    }

    #[Override]
    public function all(): array
    {
        return $this->routes;
    }
}
