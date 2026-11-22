<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Consumer;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class SettleInvoiceHandler
{
    public const SLO = 'invoice.settle';

    #[Slo(name: self::SLO, objectives: [new Availability(99.9)], strategy: StrategyPreference::Dedicated)]
    public function __invoke(SettleInvoice $message): string
    {
        return $message->invoiceId;
    }
}
