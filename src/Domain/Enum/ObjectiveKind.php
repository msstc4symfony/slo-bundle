<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\Enum;

enum ObjectiveKind: string
{
    case Availability = 'availability';
    case Latency = 'latency';
    case Combined = 'combined';
}
