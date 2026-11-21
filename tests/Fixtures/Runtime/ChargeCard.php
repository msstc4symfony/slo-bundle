<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Runtime;

final readonly class ChargeCard
{
    /**
     * @param non-empty-string $id
     * @param non-negative-int $failures attempts that throw before the handler succeeds
     */
    public function __construct(public string $id, public int $failures = 0)
    {
    }
}
