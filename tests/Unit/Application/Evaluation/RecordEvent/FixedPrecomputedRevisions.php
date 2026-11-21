<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Evaluation\RecordEvent;

use Msstc4Symfony\SloBundle\Application\Port\PrecomputedRevisionsInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Override;

final class FixedPrecomputedRevisions implements PrecomputedRevisionsInterface
{
    public int $lookups = 0;

    /**
     * @param array<string, Revision|false> $revisions "<slo>|<kind>" => precomputed answer; anything else is not precomputed
     */
    public function __construct(private readonly array $revisions = [])
    {
    }

    #[Override]
    public function revisionOf(SloName $name, ObjectiveKind $kind): Revision|false|null
    {
        $this->lookups++;

        return $this->revisions[$name->value . '|' . $kind->value] ?? null;
    }
}
