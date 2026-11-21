<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\Generate;

/**
 * @internal
 */
final readonly class Input
{
    /**
     * @param string|null $outputDir overrides GenerationConfig::$outputDir
     * @param bool $check compare with the files on disk without writing
     */
    public function __construct(
        public ?string $outputDir = null,
        public bool $check = false,
    ) {
    }
}
