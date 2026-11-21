<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\SyncFiles;

/**
 * State of the output directory before the sync; in write mode stale and missing files were written, extra ones deleted.
 *
 * @internal
 */
final readonly class Output
{
    /**
     * @param list<string> $stale on disk with other contents
     * @param list<string> $missing generated but not on disk
     * @param list<string> $extra owned file name in a managed directory, but no longer generated
     */
    public function __construct(
        public array $stale,
        public array $missing,
        public array $extra,
    ) {
    }

    public function isUpToDate(): bool
    {
        return $this->stale === [] && $this->missing === [] && $this->extra === [];
    }
}
