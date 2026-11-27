<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Grafana;

/**
 * @internal
 */
final readonly class Query
{
    /**
     * @param non-empty-string $expr
     * @param bool $instant one evaluation at the end of the range, for a stat that shows only the last value
     */
    public function __construct(
        public string $expr,
        public string $legend = '',
        public bool $instant = false,
    ) {
    }
}
