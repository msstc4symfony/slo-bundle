<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Consumer;

final readonly class SettleInvoice
{
    public function __construct(public string $invoiceId = 'i-1')
    {
    }
}
