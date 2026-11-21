<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Controller;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
#[Slo(name: 'refund.create', objectives: [new Availability(99.9)])]
final class InvokableController
{
    public function __invoke(): string
    {
        return 'refunded';
    }
}
