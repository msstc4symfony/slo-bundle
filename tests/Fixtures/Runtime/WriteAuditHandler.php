<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Runtime;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class WriteAuditHandler
{
    public const SLO = 'audit.write';

    #[Slo(name: self::SLO, objectives: [new Availability(99.9)], strategy: 'dedicated')]
    public function __invoke(WriteAudit $message): string
    {
        return $message->entry;
    }
}
