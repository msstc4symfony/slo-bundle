<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Application\Catalog\Validate\Action;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexBuilder;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexStoreInterface;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;
use Throwable;

/**
 * Surfaces catalog problems in the deploy log and writes the route index, without ever failing the cache warm-up.
 * Not optional: a container built without optional warmers would otherwise load the routes on every request.
 *
 * @internal
 */
final readonly class SloCacheWarmer implements CacheWarmerInterface
{
    public function __construct(
        private Action $validate,
        private RouterRouteReader $routes,
        private RouteIndexBuilder $builder,
        private LoggerInterface $logger,
        private RouteIndexStoreInterface $store,
    ) {
    }

    #[Override]
    public function isOptional(): bool
    {
        return false;
    }

    #[Override]
    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $this->reportCatalogProblems();

        try {
            $data = $this->builder->build($this->routes->read());
        } catch (Throwable $e) {
            $this->logger->warning('SLO route index could not be built during cache warm-up, routes are read on first use.', ['exception' => $e]);

            return [];
        }

        try {
            $this->store->write($cacheDir, $data);
        } catch (Throwable $e) {
            $this->logger->warning('SLO route index could not be written during cache warm-up, routes are read on first use.', ['exception' => $e]);
        }

        return [];
    }

    private function reportCatalogProblems(): void
    {
        try {
            $result = ($this->validate)();
            foreach ($result->errors as $message) {
                $this->logger->warning('SLO catalog error.', ['error' => $message]);
            }
            foreach ($result->warnings as $message) {
                $this->logger->warning('SLO catalog warning.', ['warning' => $message]);
            }
        } catch (Throwable $e) {
            $this->logger->warning('SLO catalog validation failed during cache warm-up.', ['exception' => $e]);
        }
    }
}
