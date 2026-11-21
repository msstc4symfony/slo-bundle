<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Application\Catalog\Validate\Action;
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
            RouteIndexFile::write($cacheDir, RouteIndexData::fromRoutes($routes, RouteIndexData::pointsOf($routes, $this->catalog)));
        } catch (Throwable $e) {
            $this->logger->warning('SLO route index could not be written during cache warm-up, routes are read on first use.', ['exception' => $e]);
        }

        return [];
    }
}
