<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Catalog\Validate;

/**
 * @internal
 */
final readonly class FieldDifference
{
    /**
     * @param non-empty-string $field
     */
    public function __construct(
        public string $field,
        public string $left,
        public string $right,
    ) {
    }
}
