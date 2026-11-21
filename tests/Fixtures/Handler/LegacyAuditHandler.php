<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Handler;

use Msstc4Symfony\SloBundle\Test\Fixtures\Message\Legacy\ProcessPayment;

final class LegacyAuditHandler
{
    public function __invoke(ProcessPayment $message): string
    {
        return $message->paymentId;
    }
}
