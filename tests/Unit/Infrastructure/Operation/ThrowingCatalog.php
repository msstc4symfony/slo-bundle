<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Operation;

use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Override;
use RuntimeException;

final class ThrowingCatalog implements SloCatalogInterface
{
    #[Override]
    public function all(): array
    {
        throw new RuntimeException('catalog down');
    }

    #[Override]
    public function get(SloName $name): ?SloDefinition
    {
        throw new RuntimeException('catalog down');
    }

    #[Override]
    public function forPoint(PointId $point): array
    {
        throw new RuntimeException('catalog down');
    }

    #[Override]
    public function ignored(): array
    {
        throw new RuntimeException('catalog down');
    }

    #[Override]
    public function conflictedNamesFor(PointId $point): array
    {
        throw new RuntimeException('catalog down');
    }
}
