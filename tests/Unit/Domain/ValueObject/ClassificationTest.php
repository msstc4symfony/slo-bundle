<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Classification;
use Msstc4Symfony\SloBundle\Domain\ValueObject\StatusSet;
use PHPUnit\Framework\TestCase;

final class ClassificationTest extends TestCase
{
    public function testDefaults(): void
    {
        $classification = Classification::defaults();

        self::assertSame(['5xx'], $classification->badStatuses->canonical());
        self::assertTrue($classification->ineligibleStatuses->isEmpty());
        self::assertTrue($classification->expectedFailureIsGood);
        self::assertFalse($classification->countAttempts);
    }

    public function testOverlapIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Classification(StatusSet::fromList(['5xx']), StatusSet::fromList([503]), true, false);
    }

    public function testDisjointSetsAreAccepted(): void
    {
        $classification = new Classification(StatusSet::fromList(['5xx']), StatusSet::fromList([429]), false, true);

        self::assertSame(['429'], $classification->ineligibleStatuses->canonical());
        self::assertFalse($classification->expectedFailureIsGood);
        self::assertTrue($classification->countAttempts);
    }

    public function testEqualsIgnoresRedundantDeclarations(): void
    {
        $a = new Classification(StatusSet::fromList(['5xx', 500]), StatusSet::fromList([429]), true, false);
        $b = new Classification(StatusSet::fromList(['5xx']), StatusSet::fromList([429]), true, false);

        self::assertTrue($a->equals($b));
        self::assertTrue(Classification::defaults()->equals(Classification::defaults()));
    }

    public function testEqualsDetectsDifferences(): void
    {
        $base = Classification::defaults();
        $bad = StatusSet::fromList(['5xx']);
        $none = StatusSet::fromList([]);

        self::assertFalse($base->equals(new Classification(StatusSet::fromList(['500']), $none, true, false)));
        self::assertFalse($base->equals(new Classification($bad, StatusSet::fromList([429]), true, false)));
        self::assertFalse($base->equals(new Classification($bad, $none, false, false)));
        self::assertFalse($base->equals(new Classification($bad, $none, true, true)));
    }

    public function testApplicabilityMatrix(): void
    {
        $matrix = [];
        foreach (PointType::cases() as $type) {
            foreach (['badStatuses', 'ineligibleStatuses', 'expectedFailureIsGood', 'countAttempts'] as $field) {
                if (Classification::appliesTo($field, $type)) {
                    $matrix[$type->value][] = $field;
                }
            }
        }

        self::assertSame([
            'http_route' => ['badStatuses', 'ineligibleStatuses'],
            'messenger_message' => ['countAttempts'],
            'logic_action' => ['expectedFailureIsGood'],
            'operation' => ['expectedFailureIsGood'],
            'scheduled_task' => ['expectedFailureIsGood'],
        ], $matrix);
        self::assertSame([PointType::LogicAction, PointType::Operation, PointType::ScheduledTask], Classification::pointTypesOf('expectedFailureIsGood'));
        self::assertSame(['http_route'], array_map(static fn (PointType $t): string => $t->value, array_values(array_filter(PointType::cases(), static fn (PointType $t): bool => $t->acceptsIncludeErrors()))));
    }

    public function testInapplicableFieldsAreThoseThatDifferFromTheDefaultOutsideTheirPointTypes(): void
    {
        $custom = new Classification(StatusSet::fromList(['4xx']), StatusSet::fromList(['503']), false, true);

        self::assertSame([], Classification::defaults()->inapplicableFieldsFor(PointType::Operation));
        self::assertSame(['expectedFailureIsGood', 'countAttempts'], $custom->inapplicableFieldsFor(PointType::HttpRoute));
        self::assertSame(['badStatuses', 'ineligibleStatuses', 'countAttempts'], $custom->inapplicableFieldsFor(PointType::LogicAction));
        self::assertSame(['badStatuses', 'ineligibleStatuses', 'expectedFailureIsGood'], $custom->inapplicableFieldsFor(PointType::MessengerMessage));
    }
}
