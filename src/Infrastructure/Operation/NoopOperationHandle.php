<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Operation;

use Msstc4Symfony\SloBundle\Application\Port\OperationHandleInterface;
use Override;
use Throwable;

/**
 * Returned for undeclared or invalid operation names.
 */
final class NoopOperationHandle implements OperationHandleInterface
{
    #[Override]
    public function succeed(): void
    {
    }

    #[Override]
    public function failExpected(?Throwable $reason = null): void
    {
    }

    #[Override]
    public function fail(?Throwable $reason = null): void
    {
    }

    #[Override]
    public function end(): void
    {
    }
}
