<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Runtime;

final readonly class WriteAudit
{
    public function __construct(public string $entry = 'audit')
    {
    }
}
