<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\Enum;

enum Strategy: string
{
    case ExistingSeries = 'existing_series';
    case Dedicated = 'dedicated';
}
