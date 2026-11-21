<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Action;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;

#[Slo(name: 'refund.action', objectives: [new Availability(99.5)], expectedFailureIsGood: false)]
final class RefundAction
{
    public function handle(): string
    {
        return 'refunded';
    }
}
