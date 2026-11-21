<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

use Throwable;

/**
 * Decides whether an exception thrown by a measured operation is an expected failure rather than an error.
 *
 * @internal
 */
interface ExpectedFailureClassifierInterface
{
    public function isExpectedFailure(Throwable $e): bool;
}
