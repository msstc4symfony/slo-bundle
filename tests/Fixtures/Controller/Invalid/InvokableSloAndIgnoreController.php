<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Controller\Invalid;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\IgnoreMonitoring;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;

#[IgnoreMonitoring('internal')]
final class InvokableSloAndIgnoreController
{
    #[Slo(name: 'internal.invoke', objectives: [new Availability(99.9)])]
    public function __invoke(): string
    {
        return 'ok';
    }
}
