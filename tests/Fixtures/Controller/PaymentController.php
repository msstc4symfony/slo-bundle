<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Controller;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Combined;
use Msstc4Symfony\SloBundle\Application\Attribute\IgnoreMonitoring;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;

final class PaymentController
{
    #[Slo(name: 'payment.create', objectives: [new Availability(99.95), new Latency('200ms', 99.0)])]
    #[Slo(name: 'payment.combined', objectives: [new Combined('1.5s', 95)], window: '7d', strategy: StrategyPreference::Dedicated)]
    public function create(): string
    {
        return 'created';
    }

    #[IgnoreMonitoring('health probe')]
    public function health(): string
    {
        return 'ok';
    }
}
