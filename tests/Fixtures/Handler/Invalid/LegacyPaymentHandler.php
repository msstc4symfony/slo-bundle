<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Handler\Invalid;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Test\Fixtures\Message\Legacy\ProcessPayment;

final class LegacyPaymentHandler
{
    #[Slo(name: 'payment.legacy', objectives: [new Availability(99.0)])]
    public function __invoke(ProcessPayment $message): string
    {
        return $message->paymentId;
    }
}
