<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent;

use Msstc4Symfony\SloBundle\Domain\ValueObject\Observation;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;

final readonly class Input
{
    public function __construct(
        public PointId $point,
        public Observation $observation,
    ) {
    }
}
