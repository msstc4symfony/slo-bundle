<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Observation;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;

final readonly class Input
{
    public function __construct(
        public PointId $point,
        public Observation $observation,
    ) {
        if ($observation->pointType !== $point->type) {
            throw new InvalidArgumentException(\sprintf('Observation of a "%s" point recorded for point "%s".', $observation->pointType->value, $point->toString()));
        }
    }
}
