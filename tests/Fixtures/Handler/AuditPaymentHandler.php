<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Handler;

use Msstc4Symfony\SloBundle\Test\Fixtures\Message\ProcessPayment;

final class AuditPaymentHandler
{
    public function __invoke(ProcessPayment $message): string
    {
        return $message->paymentId;
    }
}
