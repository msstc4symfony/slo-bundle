<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Application\Logging\WarnOnceLimiter;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Application\Port\RoutePointIndexInterface;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexBuilder;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexData;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexStoreInterface;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Read from the warmed route index, else built from the routes on first use and handed to the store, which persists it
 * outside debug mode; memoised for the process, routes are fixed per container (ruling 7). A failed build is memoised as an empty index: HTTP SLOs then record nothing until
 * the next kernel.reset retries it; a successful build survives resets.
 *
 * @internal
 */
final class RoutePointIndex implements RoutePointIndexInterface, ResetInterface
{
    /** @var array<array-key, list<PointId>>|null */
    private ?array $index = null;

    private bool $failed = false;

    /** never reset: a deterministic build failure is retried after every reset but logged once per exception class */
    private readonly WarnOnceLimiter $failureWarnings;

    public function __construct(
        private readonly RouteCatalogInterface $routes,
        private readonly SloCatalogInterface $catalog,
        private readonly RouteIndexStoreInterface $file,
        LoggerInterface $logger,
        private readonly RouteIndexBuilder $builder,
    ) {
        $this->failureWarnings = new WarnOnceLimiter($logger, 'Further SLO route index failures are not logged.', 10);
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
            $routes = $this->routes->all();
            $points = RouteIndexData::pointsOf($routes, $this->catalog);
        } catch (Throwable $e) {
            $this->failed = true;
            $this->failureWarnings->warn($e::class, 'SLO route index could not be built, HTTP SLO events are not recorded until reset; repeats are not logged.', ['exception' => $e]);

            return [];
        }
        $this->file->persist(fn (): RouteIndexData => $this->builder->build($routes));

        return $points;
    }
}
