<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

/**
 * @internal
 */
interface RouteCatalogInterface
{
    /**
     * @param non-empty-string $controller "Class::method"
     *
     * @return list<RouteInfo> sorted by route name
     */
    public function routesFor(string $controller): array;

    /**
     * @return list<RouteInfo> sorted by route name
     */
    public function all(): array;
}
