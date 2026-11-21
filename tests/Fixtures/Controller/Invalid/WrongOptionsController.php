<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Controller\Invalid;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;

final class WrongOptionsController
{
    #[Slo(name: 'payment.attempts', objectives: [new Availability(99.9)], countAttempts: true)]
    public function attempts(): string
    {
        return 'attempts';
    }

    #[Slo(name: 'payment.expected', objectives: [new Availability(99.9)], expectedFailureIsGood: false)]
    public function expected(): string
    {
        return 'expected';
    }
}
