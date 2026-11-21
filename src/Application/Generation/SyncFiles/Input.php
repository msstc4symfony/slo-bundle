<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\SyncFiles;

use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFileSet;

final readonly class Input
{
    /**
     * @param non-empty-string $root output directory
     * @param bool $checkOnly compare without writing
     */
    public function __construct(
        public GeneratedFileSet $files,
        public string $root,
        public bool $checkOnly,
    ) {
    }
}
