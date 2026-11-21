<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;

/**
 * Renders one Sloth `prometheus/v1` specification per SLO.
 *
 * @internal
 */
interface SlothSpecBuilderInterface
{
    /**
     * True when the objective is a ratio of bad to total events, the only SLI shape Sloth expresses.
     */
    public function supports(BoundObjective $objective): bool;

    /**
     * @param non-empty-list<BoundObjective> $objectives supported objectives of one SLO
     */
    public function spec(Selector $selector, array $objectives): string;
}
