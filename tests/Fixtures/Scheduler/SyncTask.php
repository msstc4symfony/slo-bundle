<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

/**
 * Runs inside the RedispatchMessage handler: a sync transport fires no worker events for it.
 */
#[AsPeriodicTask(frequency: 60, schedule: 'synced', transports: 'sync')]
#[Slo('synced.run', [new Availability(99)], deadline: '120s')]
final class SyncTask
{
    public function __invoke(): string
    {
        return 'done';
    }
}
