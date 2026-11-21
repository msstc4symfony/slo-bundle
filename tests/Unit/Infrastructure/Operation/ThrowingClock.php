<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Operation;

use Msstc4Symfony\SloBundle\Application\Port\ClockInterface;
use Override;
use RuntimeException;

final class ThrowingClock implements ClockInterface
{
    private int $calls = 0;

    /**
     * @param non-negative-int $workingCalls calls answered before the clock starts to throw
     */
    public function __construct(private readonly int $workingCalls = 0)
    {
    }

    #[Override]
    public function now(): int
    {
        if ($this->calls++ >= $this->workingCalls) {
            throw new RuntimeException('clock broken');
        }

        return 0;
    }
}
