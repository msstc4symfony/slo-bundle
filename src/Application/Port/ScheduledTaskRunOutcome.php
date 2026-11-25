<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

/**
 * How one attempt of a scheduled task ended; a retried attempt is not a final outcome and never an SLO event.
 *
 * @internal
 */
enum ScheduledTaskRunOutcome: string
{
    case Success = 'success';
    case Error = 'error';
    case ExpectedFailure = 'expected_failure';
    case Retried = 'retried';
}
