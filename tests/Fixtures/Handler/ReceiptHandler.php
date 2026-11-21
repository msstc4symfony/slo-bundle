<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Handler;

use Msstc4Symfony\SloBundle\Application\Attribute\IgnoreMonitoring;
use Msstc4Symfony\SloBundle\Test\Fixtures\Message\SendReceipt;

final class ReceiptHandler
{
    #[IgnoreMonitoring('best effort')]
    public function onReceipt(SendReceipt $message): string
    {
        return $message->paymentId;
    }
}
