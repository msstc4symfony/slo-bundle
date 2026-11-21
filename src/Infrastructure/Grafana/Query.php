<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Grafana;

final readonly class Query
{
    /**
     * @param non-empty-string $expr
     */
    public function __construct(
        public string $expr,
        public string $legend = '',
    ) {
    }
}
