<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Handler\Invalid;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Test\Fixtures\Message\Contract\AbstractPaymentMessage;

#[Slo(name: 'payment.abstract', objectives: [new Availability(99.0)])]
final class AbstractMessageHandler
{
    public function handle(AbstractPaymentMessage $message): string
    {
        return $message::class;
    }
}
