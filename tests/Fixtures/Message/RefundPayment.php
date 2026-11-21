<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage(transport: 'async')]
final readonly class RefundPayment
{
    public function __construct(public string $paymentId = 'p-1')
    {
    }
}
