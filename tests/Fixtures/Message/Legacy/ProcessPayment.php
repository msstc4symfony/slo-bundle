<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Message\Legacy;

final readonly class ProcessPayment
{
    public function __construct(public string $paymentId = 'p-1')
    {
    }
}
