<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Controller\Invalid;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\IgnoreMonitoring;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;

#[IgnoreMonitoring('internal')]
final class SloAndIgnoreController
{
    #[Slo(name: 'internal.status', objectives: [new Availability(99.9)])]
    public function status(): string
    {
        return 'ok';
    }
}
