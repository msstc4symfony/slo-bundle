<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Discovery;

use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;

final readonly class CatalogContents
{
    /**
     * @param list<SloDefinition> $definitions sorted by name, conflicted names excluded
     * @param array<non-empty-string, non-empty-list<SloDeclaration>> $conflicts name => declarations sorted by point
     * @param array<non-empty-string, non-empty-string> $ignored point string => reason
     */
    public function __construct(
        public array $definitions,
        public array $conflicts,
        public array $ignored,
        public CatalogExtras $extras,
    ) {
    }
}
