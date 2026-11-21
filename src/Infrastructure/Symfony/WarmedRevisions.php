<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Application\Binding\ObjectiveKey;
use Msstc4Symfony\SloBundle\Application\Port\PrecomputedRevisionsInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Override;

/**
 * Reads the revisions SloCacheWarmer stored in the route index; nothing in debug mode or without a warmed index.
 *
 * @internal
 */
final readonly class WarmedRevisions implements PrecomputedRevisionsInterface
{
    public function __construct(private RouteIndexStoreInterface $file)
    {
    }

    #[Override]
    public function revisionOf(SloName $name, ObjectiveKind $kind): Revision|false|null
    {
        return $this->file->revisionOf(ObjectiveKey::of($name, $kind));
    }
}
