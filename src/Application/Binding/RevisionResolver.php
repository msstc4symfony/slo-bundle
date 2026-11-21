<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Binding;

use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;

/**
 * The revision of an objective in this container: RevisionCalculator fed with the discovered failure transports.
 *
 * @internal
 */
final readonly class RevisionResolver
{
    public function __construct(
        private RevisionCalculator $calculator,
        private CatalogDiagnosticsInterface $diagnostics,
    ) {
    }

    public function revision(SloDefinition $definition, ObjectiveKind $kind): Revision
    {
        return $this->calculator->revision($definition, $kind, $this->diagnostics->failureTransports());
    }
}
