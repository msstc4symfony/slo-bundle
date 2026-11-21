<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Catalog\List;

final readonly class Output
{
    /**
     * @param list<Row> $rows sorted by name, then by point; a duplicated name has one row per declaration
     */
    public function __construct(public array $rows)
    {
    }
}
