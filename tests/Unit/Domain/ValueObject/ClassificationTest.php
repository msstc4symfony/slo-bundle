<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Domain\ValueObject;

use InvalidArgumentException;
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
}
