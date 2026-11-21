<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Controller;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\IgnoreMonitoring;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;

#[IgnoreMonitoring('admin area')]
final class AdminController
{
    #[Slo(name: 'admin.status', objectives: [new Availability(99.0)])]
    public function status(): string
    {
        return 'status';
    }

    public function settings(): string
    {
        return 'settings';
    }
}
