<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog;

use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Override;
use RuntimeException;

/**
 * Delegates to a real catalog except for the named methods, which throw.
 */
final readonly class FailingCatalog implements SloCatalogInterface, CatalogDiagnosticsInterface
{
    /**
     * @param list<non-empty-string> $failing method names
     */
    public function __construct(public CatalogDiagnosticsInterface&SloCatalogInterface $inner, private array $failing)
    {
    }

    #[Override]
    public function all(): array
    {
        $this->fail(__FUNCTION__);

        return $this->inner->all();
    }

    #[Override]
    public function get(SloName $name): ?SloDefinition
    {
        $this->fail(__FUNCTION__);

        return $this->inner->get($name);
    }

    #[Override]
    public function forPoint(PointId $point): array
    {
        $this->fail(__FUNCTION__);

        return $this->inner->forPoint($point);
    }

    #[Override]
    public function ignored(): array
    {
        $this->fail(__FUNCTION__);

        return $this->inner->ignored();
    }

    #[Override]
    public function conflictedNamesFor(PointId $point): array
    {
        $this->fail(__FUNCTION__);

        return $this->inner->conflictedNamesFor($point);
    }

    #[Override]
    public function conflicts(): array
    {
        $this->fail(__FUNCTION__);

        return $this->inner->conflicts();
    }

    #[Override]
    public function shared(): array
    {
        $this->fail(__FUNCTION__);

        return $this->inner->shared();
    }

    #[Override]
    public function extras(): CatalogExtras
    {
        $this->fail(__FUNCTION__);

        return $this->inner->extras();
    }

    #[Override]
    public function hasHttpPoints(): bool
    {
        $this->fail(__FUNCTION__);

        return $this->inner->hasHttpPoints();
    }

    #[Override]
    public function failureTransports(): array
    {
        $this->fail(__FUNCTION__);

        return $this->inner->failureTransports();
    }

    #[Override]
    public function metricBuckets(string $metric): ?array
    {
        $this->fail(__FUNCTION__);

        return $this->inner->metricBuckets($metric);
    }

    #[Override]
    public function metricLabelOf(PointId $point): ?string
    {
        $this->fail(__FUNCTION__);

        return $this->inner->metricLabelOf($point);
    }

    #[Override]
    public function handledMessageClasses(): array
    {
        $this->fail(__FUNCTION__);

        return $this->inner->handledMessageClasses();
    }

    private function fail(string $method): void
    {
        if (\in_array($method, $this->failing, true)) {
            throw new RuntimeException($method . ' broken');
        }
    }
}
