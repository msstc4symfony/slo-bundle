<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

use Throwable;

/**
 * Classifies an exception like `OperationMonitorInterface::measure()`; for bridges that decorate the monitor.
 */
interface ExpectedFailureClassifierInterface
{
    public function isExpectedFailure(Throwable $e): bool;
}
