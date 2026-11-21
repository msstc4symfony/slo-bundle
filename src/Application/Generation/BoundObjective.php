<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation;

use Msstc4Symfony\SloBundle\Application\Binding\Binding;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;

/**
 * One objective of one SLO with the series it reads and its current revision: the unit every generator renders.
 */
final readonly class BoundObjective
{
    public function __construct(
        public SloDefinition $definition,
        public Objective $objective,
        public Binding $binding,
        public Revision $revision,
    ) {
    }
}
