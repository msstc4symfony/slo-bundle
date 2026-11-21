<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Controller;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\IgnoreMonitoring;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;

final class PercentController
{
    #[IgnoreMonitoring('A/B split 50%/50%, measured by %experiment%')]
    public function split(): string
    {
        return 'split';
    }

    #[Slo('payment.percent', [new Availability(99.9)])]
    public function pay(): string
    {
        return 'pay';
    }
}
