<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\Repository;

use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;

interface SloCatalogInterface
{
    /**
     * @return list<SloDefinition> sorted by name
     */
    public function all(): array;

    public function get(SloName $name): ?SloDefinition;

    /**
     * @return list<SloDefinition>
     */
    public function forPoint(PointId $point): array;

    /**
     * @return array<string, non-empty-string> point string => reason
     */
    public function ignored(): array;

    /**
     * Names whose declarations conflict on the given point.
     *
     * @return list<non-empty-string>
     */
    public function conflictedNamesFor(PointId $point): array;
}
