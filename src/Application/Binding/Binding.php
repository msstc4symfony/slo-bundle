<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Binding;

/**
 * @internal
 */
final readonly class Binding
{
    private function __construct(
        public Strategy $strategy,
        public ?SeriesBinding $series,
    ) {
    }

    public static function existingSeries(SeriesBinding $series): self
    {
        return new self(Strategy::ExistingSeries, $series);
    }

    public static function dedicated(): self
    {
        return new self(Strategy::Dedicated, null);
    }
}
