<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Handler\Invalid;

use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Test\Fixtures\Message\SendReceipt;

final class IncludeErrorsReceiptHandler
{
    #[Slo(name: 'receipt.send', objectives: [new Latency('1s', 99.0, includeErrors: true)])]
    public function __invoke(SendReceipt $message): string
    {
        return $message->paymentId;
    }
}
