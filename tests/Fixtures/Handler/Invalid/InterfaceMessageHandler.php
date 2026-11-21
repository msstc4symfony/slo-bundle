<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Handler\Invalid;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Test\Fixtures\Message\Contract\PaymentMessageInterface;

final class InterfaceMessageHandler
{
    #[Slo(name: 'payment.any', objectives: [new Availability(99.0)])]
    public function __invoke(PaymentMessageInterface $message): string
    {
        return $message::class;
    }
}
