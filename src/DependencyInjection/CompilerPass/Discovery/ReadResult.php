<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery;

/**
 * What a discovery reader found plus the validate warnings it raised.
 *
 * @template TItem of object
 *
 * @internal
 */
final readonly class ReadResult
{
    /**
     * @param list<TItem> $items
     * @param list<non-empty-string> $warnings
     */
    public function __construct(
        public array $items,
        public array $warnings,
    ) {
    }
}
