<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\GenerateRules;

use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFileSet;

final readonly class Output
{
    /**
     * @param list<non-empty-string> $warnings
     */
    public function __construct(
        public GeneratedFileSet $files,
        public array $warnings,
    ) {
    }
}
