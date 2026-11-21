<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

interface ClockInterface
{
    /**
     * Monotonic time in nanoseconds, only differences are meaningful.
     */
    public function now(): int;
}
