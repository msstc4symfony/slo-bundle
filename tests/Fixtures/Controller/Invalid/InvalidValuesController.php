<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Controller\Invalid;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;

final class InvalidValuesController
{
    #[Slo(name: 'payment.window', objectives: [new Availability(99.9)], window: '5m')]
    public function window(): string
    {
        return 'window';
    }

    #[Slo(name: 'payment.target', objectives: [new Availability(120)])]
    public function target(): string
    {
        return 'target';
    }

    #[Slo(name: 'payment.threshold', objectives: [new Latency('fast', 99)])]
    public function threshold(): string
    {
        return 'threshold';
    }

    #[Slo(name: 'Payment Name', objectives: [new Availability(99)])]
    public function name(): string
    {
        return 'name';
    }

    #[Slo(name: 'payment.statuses', objectives: [new Availability(99)], badStatuses: ['5xx'], ineligibleStatuses: [503])]
    public function statuses(): string
    {
        return 'statuses';
    }

    // Reason: a string strategy is the value under test (TypeError at instantiation); the attribute types it as StrategyPreference.
    #[Slo(name: 'payment.strategy', objectives: [new Availability(99)], strategy: 'fast')] // @phpstan-ignore argument.type
    public function strategy(): string
    {
        return 'strategy';
    }
}
