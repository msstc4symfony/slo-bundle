<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

/**
 * Reads and normalises every route of the router. FrameworkBundle reloads the route collection on each call, so the
 * result is memoised by the callers or warmed into RouteIndexFile. Without a router (framework.router.enabled: false)
 * there are no routes.
 *
 * @internal
 */
final readonly class RouterRouteReader
{
    private const INVOKE = '__invoke';

    public function __construct(
        private ?RouterInterface $router,
        private CatalogDiagnosticsInterface $diagnostics,
    ) {
    }

    /**
     * @return list<RouteInfo> sorted by route name
     */
    public function read(): array
    {
        $routes = [];
        foreach ($this->router?->getRouteCollection()->all() ?? [] as $name => $route) {
            $name = (string) $name;
            if ($name === '') {
                continue;
            }
            $routes[] = new RouteInfo($name, $route->getPath(), $this->methods($route), $this->normalise($route->getDefault('_controller')));
        }
        usort($routes, static fn (RouteInfo $a, RouteInfo $b): int => strcmp($a->name, $b->name));

        return $routes;
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
