<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;

/**
 * Objective strategies and revisions computed when the cache was warmed, so the hot path neither reads the routes nor
 * hashes the definition.
 *
 * @internal
 */
interface PrecomputedRevisionsInterface
{
    /**
     * @return Revision|false|null the revision of a dedicated objective, false when the objective reads existing series,
     *                             null when nothing was precomputed for it (the caller resolves it itself)
     */
    public function revisionOf(SloName $name, ObjectiveKind $kind): Revision|false|null;
}
