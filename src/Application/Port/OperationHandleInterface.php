<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

use Throwable;

/**
 * The first outcome wins. Nothing is recorded until end() is called, a handle that is never ended records nothing.
 */
interface OperationHandleInterface
{
    public function succeed(): void;

    public function failExpected(?Throwable $reason = null): void;

    public function fail(?Throwable $reason = null): void;

    /**
     * Idempotent; without an explicit outcome the operation counts as a success.
     */
    public function end(): void;
}
