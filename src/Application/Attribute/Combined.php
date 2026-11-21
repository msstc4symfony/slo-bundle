<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Attribute;

use Override;

final readonly class Combined implements ObjectiveAttributeInterface
{
    public function __construct(
        public string $threshold,
        public float $target,
    ) {
    }

    #[Override]
    public function kind(): string
    {
        return 'combined';
    }
}
