<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Operation;

use Msstc4Symfony\SloBundle\Application\Port\ClockInterface;
use Override;

/**
 * @internal
 */
final class HrtimeClock implements ClockInterface
{
    #[Override]
    public function now(): int
    {
        return hrtime(true);
    }
}
