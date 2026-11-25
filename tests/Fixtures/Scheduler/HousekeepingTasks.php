<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

#[Slo('housekeeping.run', [new Availability(99)], deadline: '5m')]
final class HousekeepingTasks
{
    #[AsPeriodicTask(frequency: 600)]
    public function purgeSessions(): string
    {
        return 'sessions';
    }

    #[AsPeriodicTask(frequency: 3600)]
    public function rotateLogs(): string
    {
        return 'logs';
    }
}
