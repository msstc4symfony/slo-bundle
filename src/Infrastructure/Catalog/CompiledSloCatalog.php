<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Catalog;

use Msstc4Symfony\SloBundle\Application\Discovery\CatalogDump;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Discovery\SloDeclaration;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Override;

/**
 * @phpstan-import-type CatalogShape from CatalogDump
 *
 * @internal
 */
final readonly class CompiledSloCatalog implements SloCatalogInterface, CatalogDiagnosticsInterface
{
    /** @var list<SloDefinition> */
    private array $definitions;

    /** @var array<non-empty-string, SloDefinition> */
    private array $byName;

    /** @var array<non-empty-string, list<SloDefinition>> */
    private array $byPoint;

    /** @var array<non-empty-string, non-empty-list<SloDeclaration>> */
    private array $conflicts;

    /** @var array<non-empty-string, list<non-empty-string>> */
    private array $conflictedByPoint;

    /** @var array<non-empty-string, non-empty-string> */
    private array $ignored;

    private CatalogExtras $extras;

    /**
     * @param CatalogShape $catalog
     */
    public function __construct(array $catalog)
    {
        $contents = CatalogDump::fromArray($catalog);

        $byName = [];
        $byPoint = [];
        foreach ($contents->definitions as $definition) {
            $byName[$definition->name()->value] = $definition;
            foreach ($definition->points() as $point) {
                $byPoint[$point->toString()][] = $definition;
            }
        }

        $conflictedByPoint = [];
        foreach ($contents->conflicts as $name => $declarations) {
            foreach ($declarations as $declaration) {
                $conflictedByPoint[$declaration->point->toString()][$name] = $name;
            }
        }

        $this->definitions = $contents->definitions;
        $this->byName = $byName;
        $this->byPoint = $byPoint;
        $this->conflicts = $contents->conflicts;
        $this->conflictedByPoint = array_map(array_values(...), $conflictedByPoint);
        $this->ignored = $contents->ignored;
        $this->extras = $contents->extras;
    }

    #[Override]
    public function all(): array
    {
        return $this->definitions;
    }

    #[Override]
    public function get(SloName $name): ?SloDefinition
    {
        return $this->byName[$name->value] ?? null;
    }

    #[Override]
    public function forPoint(PointId $point): array
    {
        return $this->byPoint[$point->toString()] ?? [];
    }

    #[Override]
    public function ignored(): array
    {
        return $this->ignored;
    }

    #[Override]
    public function conflictedNamesFor(PointId $point): array
    {
        return $this->conflictedByPoint[$point->toString()] ?? [];
    }

    #[Override]
    public function conflicts(): array
    {
        return $this->conflicts;
    }

    #[Override]
    public function shared(): array
    {
        $shared = [];
        foreach ($this->definitions as $definition) {
            if (\count($definition->points()) > 1) {
                $shared[$definition->name()->value] = $definition->points();
            }
        }

        return $shared;
    }

    #[Override]
    public function extras(): CatalogExtras
    {
        return $this->extras;
    }
}
