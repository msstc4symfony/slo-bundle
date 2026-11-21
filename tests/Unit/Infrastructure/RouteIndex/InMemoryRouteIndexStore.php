<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\RouteIndex;

use Closure;
use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Binding\ObjectiveKey;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexData;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexStoreInterface;
use Override;
use RuntimeException;
use Throwable;

/**
 * Like RouteIndexFile, an instance reads the index it was opened with: what persist() and write() store is seen by
 * the next process (reopen()), never by this one.
 */
final class InMemoryRouteIndexStore implements RouteIndexStoreInterface
{
    /** @var array<string, RouteIndexData> cache dir => written index */
    public array $written = [];

    /** @var list<RouteIndexData> indexes stored by persist() */
    public array $persisted = [];

    public int $reads = 0;

    private bool $persistCalled = false;

    public function __construct(
        private readonly ?RouteIndexData $data = null,
        private readonly bool $failWrites = false,
    ) {
    }

    public function reopen(): self
    {
        return new self($this->persisted[0] ?? $this->data, $this->failWrites);
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
        if ($revision === null) {
            return null;
        }
        try {
            return $revision === '' ? false : Revision::fromHex($revision);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    #[Override]
    public function routesFor(string $controller): ?array
    {
        $this->reads++;

        return $this->data?->routesFor($controller);
    }

    #[Override]
    public function persist(Closure $build): void
    {
        if ($this->data instanceof RouteIndexData || $this->persistCalled) {
            return;
        }
        $this->persistCalled = true;
        try {
            $this->persisted[] = $build();
        } catch (Throwable) {
        }
    }

    #[Override]
    public function write(string $cacheDir, RouteIndexData $data): void
    {
        if ($this->failWrites) {
            throw new RuntimeException('store broken');
        }
        $this->written[$cacheDir] = $data;
    }
}
