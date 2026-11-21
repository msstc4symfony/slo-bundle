<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\Generate;

use Msstc4Symfony\SloBundle\Application\Generation\SyncFiles\Output as SyncOutput;

/**
 * @internal
 */
final readonly class Output
{
    /**
     * @param non-empty-string $root output directory without a trailing slash
     * @param list<non-empty-string> $warnings
     */
    public function __construct(
        public string $root,
        public SyncOutput $files,
        public array $warnings,
    ) {
    }
}
