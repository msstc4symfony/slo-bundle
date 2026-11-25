<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use RuntimeException;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

#[AsPeriodicTask(frequency: 60)]
#[Slo('cleanup.run', [new Availability(99), new Latency('120s', 95)])]
final class CleanupTask
{
    public static bool $failing = false;

    public function __invoke(): string
    {
        if (self::$failing) {
            throw new RuntimeException('Cleanup failed.');
        }

        return 'cleaned';
    }
}
