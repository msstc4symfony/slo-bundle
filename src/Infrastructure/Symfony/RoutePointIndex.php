<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Application\Port\RoutePointIndexInterface;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Read from the warmed route index, else built from the routes on first use; memoised for the process, routes are
 * fixed per container (ruling 7). A failed build is memoised as an empty index: logged once, HTTP SLOs then record
 * nothing until the next kernel.reset retries it; a successful build survives resets.
 *
 * @internal
 */
final class RoutePointIndex implements RoutePointIndexInterface, ResetInterface
{
    /** @var array<array-key, list<PointId>>|null */
    private ?array $index = null;

    private bool $failed = false;

    public function __construct(
        private readonly RouteCatalogInterface $routes,
        private readonly SloCatalogInterface $catalog,
        private readonly RouteIndexStoreInterface $file,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function pointsFor(string $route): array
    {
        if ($this->index === null) {
            $warmed = $this->file->pointsFor($route);
            if ($warmed !== null) {
                return $warmed;
            }
        }
        $this->index ??= $this->build();

        return $this->index[$route] ?? [];
    }

    #[Override]
    public function reset(): void
    {
        if ($this->failed) {
            $this->failed = false;
            $this->index = null;
        }
    }

    /**
     * @return array<array-key, list<PointId>>
     */
    private function build(): array
    {
        try {
            return RouteIndexData::pointsOf($this->routes->all(), $this->catalog);
        } catch (Throwable $e) {
            $this->failed = true;
            $this->logger->warning('SLO route index could not be built, HTTP SLO events are not recorded until reset.', ['exception' => $e]);

            return [];
        }
    }
}
