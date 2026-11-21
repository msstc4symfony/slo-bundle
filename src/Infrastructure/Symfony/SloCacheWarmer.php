<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Binding\RevisionResolver;
use Msstc4Symfony\SloBundle\Application\Catalog\Validate\Action;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Throwable;

/**
 * Surfaces catalog problems in the deploy log and writes the route index, without ever failing the cache warm-up.
 *
 * @internal
 */
final readonly class SloCacheWarmer implements CacheWarmerInterface
{
    public function __construct(
        private Action $validate,
        private RouterRouteReader $routes,
        private SloCatalogInterface $catalog,
        private LoggerInterface $logger,
        private CatalogDiagnosticsInterface $diagnostics,
        private RevisionResolver $revisions,
    ) {
    }

    #[Override]
    public function isOptional(): bool
    {
        return true;
    }

    #[Override]
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        try {
            $result = ($this->validate)();
            foreach ($result->errors as $message) {
                $this->logger->warning('SLO catalog error: ' . $message);
            }
            foreach ($result->warnings as $message) {
                $this->logger->warning('SLO catalog warning: ' . $message);
            }
        } catch (Throwable $e) {
            $this->logger->warning('SLO catalog validation failed during cache warm-up.', ['exception' => $e]);
        }

        try {
            $routes = $this->routes->read();
            $points = RouteIndexData::pointsOf($routes, $this->catalog);
            RouteIndexFile::write($cacheDir, RouteIndexData::fromRoutes($routes, $points, $this->revisionsOf(RouteIndexData::fromRoutes($routes, $points))));
        } catch (Throwable $e) {
            $this->logger->warning('SLO route index could not be written during cache warm-up, routes are read on first use.', ['exception' => $e]);
        }

        return [];
    }

    /**
     * Resolved against the routes just read, not the router service's cache; an objective that fails to resolve is left
     * out and resolved at runtime.
     *
     * @return array<non-empty-string, string>
     */
    private function revisionsOf(RouteCatalogInterface $routes): array
    {
        $resolver = new MetricBindingResolver($routes, $this->diagnostics);
        $revisions = [];
        foreach ($this->catalog->all() as $definition) {
            foreach ($definition->objectives() as $objective) {
                try {
                    $revisions[$definition->name()->value . '|' . $objective->kind->value] = $resolver->isDedicated($definition, $objective->kind)
                        ? $this->revisions->revision($definition, $objective->kind)->value
                        : '';
                } catch (Throwable $e) {
                    $this->logger->warning('SLO objective could not be resolved during cache warm-up, it is resolved on first use.', ['slo' => $definition->name()->value, 'objective' => $objective->kind->value, 'exception' => $e]);
                }
            }
        }

        return $revisions;
    }
}
