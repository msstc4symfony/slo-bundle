<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Controller;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\IgnoreMonitoring;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;

#[Slo(name: 'report.read', objectives: [new Availability(99.5)])]
final class ReportController
{
    public function list(): string
    {
        return 'list';
    }

    #[IgnoreMonitoring('debug only')]
    public function debug(): string
    {
        return 'debug';
    }
}
