<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Symfony\Component\Scheduler\Attribute\AsCronTask;

final class ReportTasks
{
    #[AsCronTask('0 3 * * *')]
    #[AsCronTask('0 15 * * *')]
    #[Slo('report.daily', [new Availability(99)], deadline: '10m')]
    public function daily(): string
    {
        return 'daily';
    }

    #[AsCronTask('0 4 * * 1')]
    public function weekly(): string
    {
        return 'weekly';
    }
}
