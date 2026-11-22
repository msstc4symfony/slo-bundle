<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Consumer;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class ArchiveInvoiceHandler
{
    public function __invoke(ArchiveInvoice $message): string
    {
        return $message->invoiceId;
    }
}
