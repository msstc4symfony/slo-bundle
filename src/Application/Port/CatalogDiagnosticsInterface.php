<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Discovery\SloDeclaration;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;

/**
 * Read side of the catalog that reports problems instead of serving the runtime.
 *
 * @internal
 */
interface CatalogDiagnosticsInterface
{
    /**
     * Names declared differently on several points (ruling 1); absent from the catalog's definitions.
     *
     * @return array<non-empty-string, non-empty-list<SloDeclaration>> sorted by name
     */
    public function conflicts(): array;

    /**
     * SLOs merged from identical declarations on more than one point.
     *
     * @return array<non-empty-string, non-empty-list<PointId>>
     */
    public function shared(): array;

    public function extras(): CatalogExtras;
}
