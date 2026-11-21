<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Coverage\Analyze;

use InvalidArgumentException;

/**
 * @internal
 */
final readonly class Output
{
    /** @var array<value-of<CoverageKind>, KindCoverage> */
    private array $kinds;

    /**
     * @param list<PointCoverage> $points sorted by kind, then point
     * @param list<KindCoverage> $kinds one per CoverageKind
     * @param non-negative-int $invalid
     */
    public function __construct(
        public array $points,
        array $kinds,
        public int $invalid,
    ) {
        $indexed = [];
        foreach ($kinds as $coverage) {
            $indexed[$coverage->kind->value] = $coverage;
        }
        foreach (CoverageKind::cases() as $kind) {
            if (!isset($indexed[$kind->value])) {
                throw new InvalidArgumentException(\sprintf('Coverage of the "%s" kind is missing.', $kind->value));
            }
        }
        $this->kinds = $indexed;
    }

    public function of(CoverageKind $kind): KindCoverage
    {
        return $this->kinds[$kind->value];
    }

    /**
     * @return list<KindCoverage> in CoverageKind order
     */
    public function kinds(): array
    {
        return array_map($this->of(...), CoverageKind::cases());
    }

    /**
     * @param array<value-of<CoverageKind>, float> $required percent per kind, kinds without an entry are not checked
     *
     * @return list<CoverageKind>
     */
    public function belowThreshold(array $required): array
    {
        $below = [];
        foreach ($this->kinds() as $coverage) {
            if (isset($required[$coverage->kind->value]) && $coverage->isBelow($required[$coverage->kind->value])) {
                $below[] = $coverage->kind;
            }
        }

        return $below;
    }
}
