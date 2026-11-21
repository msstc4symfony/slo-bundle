<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Observation;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;

final readonly class Input
{
    /**
     * @throws InvalidArgumentException when the observation is of another point type: build Input inside the caller's
     *                                  own try, EventRecorderInterface::record() is fail-open but this constructor is not
     */
    public function __construct(
        public PointId $point,
        public Observation $observation,
    ) {
        if ($observation->pointType !== $point->type) {
            throw new InvalidArgumentException(\sprintf('Observation of a "%s" point recorded for point "%s".', $observation->pointType->value, $point->toString()));
        }
    }
}
