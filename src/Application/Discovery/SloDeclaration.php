<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Discovery;

use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;

/**
 * One declaration of a conflicted SLO name (ruling 1): the point and the single-point definition declared there.
 *
 * @internal
 */
final readonly class SloDeclaration
{
    public function __construct(
        public PointId $point,
        public SloDefinition $definition,
    ) {
    }
}
