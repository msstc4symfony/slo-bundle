<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Handler;

use Msstc4Symfony\SloBundle\Test\Fixtures\Message\RefundPayment;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class RefundPaymentHandler
{
    public function __invoke(RefundPayment $message): string
    {
        return $message->paymentId;
    }
}
