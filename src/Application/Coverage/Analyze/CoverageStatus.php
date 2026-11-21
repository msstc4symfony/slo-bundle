<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Coverage\Analyze;

/**
 * Coverage of one point; the values are part of the slo:coverage JSON, the enum itself is internal.
 *
 * @internal
 */
enum CoverageStatus: string
{
    case Monitored = 'monitored';
    case Unmonitored = 'unmonitored';
    case Ignored = 'ignored';
    case Invalid = 'invalid';
    case Unsupported = 'unsupported';
}
