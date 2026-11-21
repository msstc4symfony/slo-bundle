<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Handler;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Test\Fixtures\Message\ProcessPayment;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ProcessPaymentHandler
{
    #[Slo(name: 'payment.process', objectives: [new Availability(99.9)], countAttempts: true)]
    public function __invoke(ProcessPayment $message): string
    {
        return $message->paymentId;
    }
}
