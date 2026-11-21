<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\RouteIndex;

use Msstc4Symfony\SloBundle\Application\Binding\ObjectiveKey;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexData;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexStoreInterface;
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
    public function pointsFor(string $route): ?array
    {
        $this->reads++;

        return $this->data?->pointsFor($route);
    }

    #[Override]
    public function revisionOf(ObjectiveKey $key): Revision|false|null
    {
        $this->reads++;
        $revision = $this->data?->revisions()[$key->value] ?? null;

        return match ($revision) {
            null => null,
            '' => false,
            default => Revision::fromHex($revision),
        };
    }

    #[Override]
    public function routesFor(string $controller): ?array
    {
        $this->reads++;

        return $this->data?->routesFor($controller);
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
