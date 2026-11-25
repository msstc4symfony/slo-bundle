<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler;

use Symfony\Component\Scheduler\Attribute\AsPeriodicTask;

#[AsPeriodicTask(frequency: 300)]
final class UnmonitoredTask
{
    public function __invoke(): string
    {
        return 'done';
    }
}
