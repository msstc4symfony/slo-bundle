<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Attribute;

use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Override;

final readonly class Availability implements ObjectiveAttributeInterface
{
    public function __construct(public float $target)
    {
    }

    #[Override]
    public function kind(): ObjectiveKind
    {
        return ObjectiveKind::Availability;
    }
}
