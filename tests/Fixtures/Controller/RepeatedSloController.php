<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Controller;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;

final class RepeatedSloController
{
    #[Slo(name: 'payment.create', objectives: [new Availability(99.9)])]
    #[Slo(name: 'payment.create', objectives: [new Availability(99.9)])]
    public function create(): string
    {
        return 'created';
    }
}
