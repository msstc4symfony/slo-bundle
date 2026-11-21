<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\Enum;

enum CoverageStatus: string
{
    case Monitored = 'monitored';
    case Unmonitored = 'unmonitored';
    case Ignored = 'ignored';
    case Invalid = 'invalid';
    case Unsupported = 'unsupported';
}
