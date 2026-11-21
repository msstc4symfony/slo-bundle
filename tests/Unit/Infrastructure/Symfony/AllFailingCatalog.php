<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Override;
use RuntimeException;

/**
 * Point lookups work, the full listing fails (a corrupt definition in the dump).
 */
final readonly class AllFailingCatalog implements SloCatalogInterface
{
    public function __construct(private SloCatalogInterface $inner)
    {
    }

    #[Override]
    public function all(): array
    {
        throw new RuntimeException('catalog listing broken');
    }

    #[Override]
    public function get(SloName $name): ?SloDefinition
    {
        return $this->inner->get($name);
    }

    #[Override]
    public function forPoint(PointId $point): array
    {
        return $this->inner->forPoint($point);
    }

    #[Override]
    public function ignored(): array
    {
        return $this->inner->ignored();
    }

    #[Override]
    public function conflictedNamesFor(PointId $point): array
    {
        return $this->inner->conflictedNamesFor($point);
    }
}
