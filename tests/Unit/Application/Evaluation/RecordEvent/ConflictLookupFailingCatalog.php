<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Evaluation\RecordEvent;

use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Override;
use RuntimeException;

final readonly class ConflictLookupFailingCatalog implements SloCatalogInterface
{
    public function __construct(public CompiledSloCatalog $inner)
    {
    }

    #[Override]
    public function all(): array
    {
        return $this->inner->all();
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
        throw new RuntimeException('conflict lookup broken');
    }
}
