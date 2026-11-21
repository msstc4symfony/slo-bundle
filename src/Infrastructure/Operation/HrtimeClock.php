<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Operation;

use Msstc4Symfony\SloBundle\Application\Port\MonotonicClockInterface;
use Override;

/**
 * @internal
 */
final class HrtimeClock implements MonotonicClockInterface
{
    #[Override]
    public function now(): int
    {
        return hrtime(true);
    }
}
