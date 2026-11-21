<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation;

use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;

final readonly class BoundObjectiveCollector
{
    public function __construct(
        private SloCatalogInterface $catalog,
        private MetricBindingResolver $bindings,
        private RevisionCalculator $revisions,
    ) {
    }

    /**
     * Conflicting declarations are not in the catalog and get nothing.
     *
     * @return list<BoundObjective> by SLO name, then objective kind in declaration order of ObjectiveKind
     */
    public function collect(): array
    {
        $order = array_flip(array_map(static fn (ObjectiveKind $kind): string => $kind->value, ObjectiveKind::cases()));
        $bound = [];
        foreach ($this->catalog->all() as $definition) {
            $objectives = $definition->objectives();
            usort($objectives, static fn (Objective $a, Objective $b): int => $order[$a->kind->value] <=> $order[$b->kind->value]);
            foreach ($objectives as $objective) {
                $bound[] = new BoundObjective(
                    $definition,
                    $objective,
                    $this->bindings->resolve($definition, $objective),
                    $this->revisions->revision($definition, $objective->kind),
                );
            }
        }

        return $bound;
    }
}
