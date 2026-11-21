<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Attribute;

use Override;

final readonly class Availability implements ObjectiveAttributeInterface
{
    public function __construct(public float $target)
    {
    }

    #[Override]
    public function kind(): string
    {
        return 'availability';
    }
}
