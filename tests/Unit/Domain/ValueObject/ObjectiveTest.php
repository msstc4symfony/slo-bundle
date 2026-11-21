<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Domain\ValueObject;

use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\ValueObject\LatencyThreshold;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Target;
use PHPUnit\Framework\TestCase;

final class ObjectiveTest extends TestCase
{
    public function testAvailability(): void
    {
        $objective = Objective::availability(Target::fromPercent(99.9));

        self::assertSame(ObjectiveKind::Availability, $objective->kind);
        self::assertNull($objective->threshold);
        self::assertFalse($objective->includeErrors);
    }

    public function testLatency(): void
    {
        $threshold = LatencyThreshold::fromString('300ms');
        $objective = Objective::latency($threshold, Target::fromPercent(95.0), true);

        self::assertSame(ObjectiveKind::Latency, $objective->kind);
        self::assertSame($threshold, $objective->threshold);
        self::assertTrue($objective->includeErrors);
    }

    public function testLatencyDefaultsToExcludingErrors(): void
    {
        self::assertFalse(Objective::latency(LatencyThreshold::fromString('1s'), Target::fromPercent(95.0))->includeErrors);
    }

    public function testCombined(): void
    {
        $objective = Objective::combined(LatencyThreshold::fromString('1s'), Target::fromPercent(99.0));

        self::assertSame(ObjectiveKind::Combined, $objective->kind);
        self::assertNotNull($objective->threshold);
        self::assertFalse($objective->includeErrors);
    }

    public function testEqualsIgnoresInstanceIdentity(): void
    {
        $a = Objective::latency(LatencyThreshold::fromString('300ms'), Target::fromPercent(95.0), true);
        $b = Objective::latency(LatencyThreshold::fromString('0.3s'), Target::fromPercent(95.0), true);

        self::assertTrue($a->equals($b));
        self::assertTrue(Objective::availability(Target::fromPercent(99.0))->equals(Objective::availability(Target::fromPercent(99.0))));
    }

    public function testEqualsDetectsDifferences(): void
    {
        $base = Objective::latency(LatencyThreshold::fromString('300ms'), Target::fromPercent(95.0));

        self::assertFalse($base->equals(Objective::latency(LatencyThreshold::fromString('400ms'), Target::fromPercent(95.0))));
        self::assertFalse($base->equals(Objective::latency(LatencyThreshold::fromString('300ms'), Target::fromPercent(99.0))));
        self::assertFalse($base->equals(Objective::latency(LatencyThreshold::fromString('300ms'), Target::fromPercent(95.0), true)));
        self::assertFalse($base->equals(Objective::combined(LatencyThreshold::fromString('300ms'), Target::fromPercent(95.0))));
        self::assertFalse($base->equals(Objective::availability(Target::fromPercent(95.0))));
        self::assertFalse(Objective::availability(Target::fromPercent(95.0))->equals($base));
    }
}
