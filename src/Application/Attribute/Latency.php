<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Attribute;

use Override;

final readonly class Latency implements ObjectiveAttributeInterface
{
    public function __construct(
        public string $threshold,
        public float $target,
        public bool $includeErrors = false,
    ) {
    }

    #[Override]
    public function kind(): string
    {
        return 'latency';
    }
}
