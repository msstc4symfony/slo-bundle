<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Catalog\List;

final readonly class ObjectiveRow
{
    /**
     * @param non-empty-string $kind
     * @param non-empty-string|null $threshold
     * @param non-empty-string $revision
     */
    public function __construct(
        public string $kind,
        public float $target,
        public ?string $threshold,
        public string $revision,
    ) {
    }
}
