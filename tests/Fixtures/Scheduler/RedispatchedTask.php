<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

#[AsPeriodicTask(frequency: 60, schedule: 'redispatch', transports: 'async')]
#[Slo('redispatched.run', [new Availability(99)], deadline: '120s')]
final class RedispatchedTask
{
    public function __invoke(): string
    {
        return 'done';
    }
}
