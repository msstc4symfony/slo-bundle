<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Override;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Route index built lazily from the router on first use and memoised: routes are fixed per container (ruling 7).
 */
final class RouterRouteCatalog implements RouteCatalogInterface
{
    private const INVOKE = '__invoke';

    /** @var list<RouteInfo>|null */
    private ?array $routes = null;

    /** @var array<string, list<RouteInfo>>|null */
    private ?array $byController = null;

    public function __construct(
        private readonly RouterInterface $router,
        private readonly CatalogDiagnosticsInterface $diagnostics,
    ) {
    }

    #[Override]
    public function routesFor(string $controller): array
    {
        $this->build();

        return $this->byController[$controller] ?? [];
    }

    #[Override]
    public function all(): array
    {
        $this->build();

        return $this->routes ?? [];
    }

    private function build(): void
    {
        if ($this->routes !== null) {
            return;
        }

        $routes = [];
        $byController = [];
        foreach ($this->router->getRouteCollection()->all() as $name => $route) {
            $name = (string) $name;
            if ($name === '') {
                continue;
            }
            $controller = $this->normalise($route->getDefault('_controller'));
            $info = new RouteInfo($name, $route->getPath(), $this->methods($route), $controller);
            $routes[] = $info;
            if ($controller !== null) {
                $byController[$controller][] = $info;
            }
        }
        usort($routes, static fn (RouteInfo $a, RouteInfo $b): int => strcmp($a->name, $b->name));
        foreach ($byController as &$list) {
            usort($list, static fn (RouteInfo $a, RouteInfo $b): int => strcmp($a->name, $b->name));
        }
        unset($list);

        $this->routes = $routes;
        $this->byController = $byController;
    }

    /**
     * @return list<string>
     */
    private function methods(Route $route): array
    {
        return array_values($route->getMethods());
    }

    /**
     * @return non-empty-string|null
     */
    private function normalise(mixed $controller): ?string
    {
        if (\is_array($controller) && \count($controller) === 2) {
            [$class, $method] = array_values($controller);
            if (\is_object($class)) {
                $class = $class::class;
            }

            return \is_string($class) && \is_string($method) ? $this->join($class, $method) : null;
        }

        if (!\is_string($controller) || $controller === '') {
            return null;
        }

        if (str_contains($controller, '::')) {
            [$left, $method] = explode('::', $controller, 2);

            return $this->join($left, $method);
        }

        return $this->join($controller, self::INVOKE);
    }

    /**
     * @return non-empty-string|null
     */
    private function join(string $classOrServiceId, string $method): ?string
    {
        $class = ltrim($classOrServiceId, '\\');
        if ($class === '' || $method === '') {
            return null;
        }

        return $this->resolveClass($class) . '::' . $method;
    }

    /**
     * @param non-empty-string $classOrServiceId
     *
     * @return non-empty-string
     */
    private function resolveClass(string $classOrServiceId): string
    {
        return $this->diagnostics->extras()->controllerServices[$classOrServiceId] ?? $classOrServiceId;
    }
}
