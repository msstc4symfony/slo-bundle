<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage(transport: 'sync')]
final readonly class AuditNotice
{
}
