<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Message;

final readonly class SendReceipt
{
    public function __construct(public string $paymentId = 'p-1')
    {
    }
}
