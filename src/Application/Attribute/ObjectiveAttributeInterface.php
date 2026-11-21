<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Attribute;

use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;

interface ObjectiveAttributeInterface
{
    public function kind(): ObjectiveKind;
}
