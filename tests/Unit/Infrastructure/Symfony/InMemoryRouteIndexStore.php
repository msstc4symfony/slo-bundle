<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouteIndexData;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouteIndexStoreInterface;
use Override;
use RuntimeException;

final class InMemoryRouteIndexStore implements RouteIndexStoreInterface
{
    /** @var array<string, RouteIndexData> cache dir => written index */
    public array $written = [];

    public int $reads = 0;

    public function __construct(
        private ?RouteIndexData $data = null,
        private readonly bool $failWrites = false,
    ) {
    }

    #[Override]
    public function load(): ?RouteIndexData
    {
        $this->reads++;

        return $this->data;
    }

    #[Override]
    public function points(): ?array
    {
        $this->reads++;
        if (!$this->data instanceof RouteIndexData) {
            return null;
        }
        $points = [];
        foreach ($this->data->points() as $route => $list) {
            if ($list !== []) {
                $points[$route] = $list;
            }
        }

        return $points;
    }

    #[Override]
    public function revisions(): ?array
    {
        $this->reads++;

        return $this->data?->revisions();
    }

    #[Override]
    public function write(string $cacheDir, RouteIndexData $data): void
    {
        if ($this->failWrites) {
            throw new RuntimeException('store broken');
        }
        $this->written[$cacheDir] = $data;
        $this->data = $data;
    }
}
