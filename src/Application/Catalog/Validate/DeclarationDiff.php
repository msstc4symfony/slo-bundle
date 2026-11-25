<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Catalog\Validate;

use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;

/**
 * Fields on which two declarations of one SLO name differ.
 *
 * @internal
 */
final class DeclarationDiff
{
    private const ABSENT = 'absent';

    /**
     * @return list<FieldDifference>
     */
    public static function between(SloDefinition $left, SloDefinition $right): array
    {
        $differences = [];
        $add = static function (string $field, string $a, string $b) use (&$differences): void {
            if ($a !== $b && $field !== '') {
                $differences[] = new FieldDifference($field, $a, $b);
            }
        };

        $add('point type', $left->pointType()->value, $right->pointType()->value);
        if ($left->window()->seconds() !== $right->window()->seconds()) {
            $add('window', $left->window()->toString(), $right->window()->toString());
        }
        $add('strategy', $left->strategyPreference()->value, $right->strategyPreference()->value);
        $add('deadline', $left->runDeadline()?->toString() ?? '-', $right->runDeadline()?->toString() ?? '-');

        $qualify = \count($left->objectives()) > 1 || \count($right->objectives()) > 1;
        foreach (ObjectiveKind::cases() as $kind) {
            $a = $left->objective($kind);
            $b = $right->objective($kind);
            if (!$a instanceof Objective && !$b instanceof Objective) {
                continue;
            }
            if (!$a instanceof Objective || !$b instanceof Objective) {
                $add($kind->value . ' objective', $a instanceof Objective ? 'declared' : self::ABSENT, $b instanceof Objective ? 'declared' : self::ABSENT);
                continue;
            }
            $prefix = $qualify ? $kind->value . ' ' : '';
            $add($prefix . 'target', (string) $a->target->percent, (string) $b->target->percent);
            $add($prefix . 'threshold', $a->threshold?->toString() ?? self::ABSENT, $b->threshold?->toString() ?? self::ABSENT);
            $add($prefix . 'include errors', self::flag($a->includeErrors), self::flag($b->includeErrors));
        }

        $x = $left->classification();
        $y = $right->classification();
        $add('bad statuses', implode(',', $x->badStatuses->canonical()), implode(',', $y->badStatuses->canonical()));
        $add('ineligible statuses', implode(',', $x->ineligibleStatuses->canonical()), implode(',', $y->ineligibleStatuses->canonical()));
        $add('expected failure is good', self::flag($x->expectedFailureIsGood), self::flag($y->expectedFailureIsGood));
        $add('count attempts', self::flag($x->countAttempts), self::flag($y->countAttempts));

        return $differences;
    }

    private static function flag(bool $value): string
    {
        return $value ? 'true' : 'false';
    }
}
