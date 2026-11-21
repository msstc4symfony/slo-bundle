<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\GenerateSloth;

use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Application\Generation\BoundObjectiveCollector;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFileSet;
use Msstc4Symfony\SloBundle\Application\Port\SlothSpecBuilderInterface;

final readonly class Action
{
    public const DIRECTORY = 'sloth';

    public function __construct(
        private BoundObjectiveCollector $objectives,
        private SlothSpecBuilderInterface $builder,
    ) {
    }

    public function __invoke(Input $input): Output
    {
        if (!$input->enabled) {
            return new Output(new GeneratedFileSet([self::DIRECTORY], []), []);
        }

        $warnings = [];
        $bySlo = [];
        foreach ($this->objectives->collect() as $bound) {
            $name = $bound->definition->name()->value;
            if (!$this->builder->supports($bound)) {
                $warnings[] = \sprintf('SLO %s %s is not expressible as a Sloth ratio SLI and is skipped', $name, $bound->objective->kind->value);
                continue;
            }
            $bySlo[$name][] = $bound;
        }

        $files = [];
        foreach ($bySlo as $name => $objectives) {
            /** @var non-empty-list<BoundObjective> $objectives */
            $files[] = new GeneratedFile(self::DIRECTORY . '/slo-' . $name . '.yml', $this->builder->spec($input->selector, $objectives));
        }

        return new Output(new GeneratedFileSet([self::DIRECTORY], $files), $warnings);
    }
}
