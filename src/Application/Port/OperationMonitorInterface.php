<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

use Closure;

interface OperationMonitorInterface
{
    public function start(string $name): OperationHandleInterface;

    /**
     * A throwable is classified (expected failure or error), recorded and rethrown unchanged.
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @return T
     */
    public function measure(string $name, Closure $operation): mixed;
}
