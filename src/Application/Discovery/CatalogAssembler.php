<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Discovery;

use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;

/**
 * Collects raw declarations per point, then builds the catalog: identical declarations of one name merge into a
 * multi-point SLO, differing ones (or the same name twice on one point) become conflicts (ruling 1).
 *
 * @internal
 */
final class CatalogAssembler
{
    /** @var array<non-empty-string, array{point: PointId, slos: list<Slo>, ignore: non-empty-string|null}> */
    private array $points = [];

    public function declare(PointId $point, Slo $slo): void
    {
        $this->entry($point);
        $this->points[$point->toString()]['slos'][] = $slo;
    }

    /**
     * The first reason declared for a point is kept.
     *
     * @param non-empty-string $reason
     */
    public function ignore(PointId $point, string $reason): void
    {
        $this->entry($point);
        $this->points[$point->toString()]['ignore'] ??= $reason;
    }

    public function assemble(DefinitionFactory $factory, DiscoveryErrors $errors, CatalogExtras $extras): CatalogContents
    {
        $points = $this->points;
        ksort($points, SORT_STRING);

        $ignored = [];
        $declarations = [];
        foreach ($points as $key => $entry) {
            if ($entry['ignore'] !== null) {
                if ($entry['slos'] !== []) {
                    $errors->add($key . ': #[Slo] and #[IgnoreMonitoring] are declared on one point.');

                    continue;
                }
                $ignored[$key] = $entry['ignore'];

                continue;
            }
            foreach ($entry['slos'] as $slo) {
                $definition = $factory->fromAttribute($slo, $entry['point'], $errors);
                if ($definition instanceof SloDefinition) {
                    $declarations[$definition->name()->value][] = new SloDeclaration($entry['point'], $definition);
                }
            }
        }
        ksort($declarations, SORT_STRING);

        $definitions = [];
        $conflicts = [];
        foreach ($declarations as $name => $list) {
            $merged = $this->merge($list);
            if ($merged instanceof SloDefinition) {
                $definitions[] = $merged;
            } else {
                $conflicts[$name] = $list;
            }
        }

        $hasHttpPoints = array_filter($definitions, static fn (SloDefinition $d): bool => $d->pointType() === PointType::HttpRoute) !== []
            || array_filter(array_merge(...array_values($conflicts)), static fn (SloDeclaration $d): bool => $d->point->type === PointType::HttpRoute) !== [];

        return new CatalogContents($definitions, $conflicts, $ignored, $extras->withHttpPoints($hasHttpPoints));
    }

    /**
     * @param non-empty-list<SloDeclaration> $declarations sorted by point
     */
    private function merge(array $declarations): ?SloDefinition
    {
        $merged = $declarations[0]->definition;
        $seen = [$declarations[0]->point->toString() => true];
        $spellings = [$merged->window()->toString() => true];
        foreach (\array_slice($declarations, 1) as $declaration) {
            $key = $declaration->point->toString();
            if (isset($seen[$key]) || !$merged->sameSemanticsAs($declaration->definition)) {
                return null;
            }
            $seen[$key] = true;
            $spellings[$declaration->definition->window()->toString()] = true;
            $merged = $merged->withPoint($declaration->point);
        }

        // Equal windows spelled differently ("30d", "1mo") must not depend on which point sorts first.
        return \count($spellings) > 1 ? $merged->withWindow($merged->window()->canonical()) : $merged;
    }

    private function entry(PointId $point): void
    {
        $this->points[$point->toString()] ??= ['point' => $point, 'slos' => [], 'ignore' => null];
    }
}
