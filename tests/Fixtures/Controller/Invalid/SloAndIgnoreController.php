<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Controller\Invalid;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\IgnoreMonitoring;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;

final class SloAndIgnoreController
{
    #[Slo(name: 'internal.status', objectives: [new Availability(99.9)])]
    #[IgnoreMonitoring('internal')]
    public function status(): string
    {
        return 'ok';
    }
}
