<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\Enum;

enum StrategyPreference: string
{
    case Auto = 'auto';
    case Dedicated = 'dedicated';
}
