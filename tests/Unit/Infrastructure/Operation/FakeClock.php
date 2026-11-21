<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Operation;

use Msstc4Symfony\SloBundle\Application\Port\ClockInterface;
use Override;

final class FakeClock implements ClockInterface
{
    public function __construct(public int $nanoseconds = 0)
    {
    }

    #[Override]
    public function now(): int
    {
        return $this->nanoseconds;
    }
}
