<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Bridge;

use Closure;
use Msstc4Symfony\SloBundle\Application\Port\OperationHandleInterface;
use Msstc4Symfony\SloBundle\Application\Port\OperationMonitorInterface;
use Override;

final readonly class DecoratingOperationMonitor implements OperationMonitorInterface
{
    public function __construct(public OperationMonitorInterface $inner)
    {
    }

    #[Override]
    public function start(string $name): OperationHandleInterface
    {
        return $this->inner->start($name);
    }

    #[Override]
    public function measure(string $name, Closure $operation): mixed
    {
        return $this->inner->measure($name, $operation);
    }
}
