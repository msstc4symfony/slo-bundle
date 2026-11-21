<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Application\Port\RoutePointIndexInterface;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read from the warmed RouteIndexFile, else built from the routes on first use; memoised for the process, routes are
 * fixed per container (ruling 7). A failed build is memoised as an empty index: logged once, HTTP SLOs then record
 * nothing until the process restarts.
 *
 * @internal
 */
final class RoutePointIndex implements RoutePointIndexInterface
{
    /** @var array<non-empty-string, list<PointId>>|null */
    private ?array $index = null;

    public function __construct(
        private readonly RouteCatalogInterface $routes,
        private readonly SloCatalogInterface $catalog,
        private readonly RouteIndexFile $file,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function pointsFor(string $route): array
    {
        $this->index ??= $this->build();

        return $this->index[$route] ?? [];
    }

    /**
     * @return array<non-empty-string, list<PointId>>
     */
    private function build(): array
    {
        try {
            return $this->file->load()?->points() ?? RouteIndexData::pointsOf($this->routes->all(), $this->catalog);
        } catch (Throwable $e) {
            $this->logger->warning('SLO route index could not be built, HTTP SLO events are not recorded.', ['exception' => $e]);

            return [];
        }
    }
}
