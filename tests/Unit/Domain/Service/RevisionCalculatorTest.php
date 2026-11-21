<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Domain\Service;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Classification;
use Msstc4Symfony\SloBundle\Domain\ValueObject\LatencyThreshold;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Domain\ValueObject\StatusSet;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Target;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Window;
use PHPUnit\Framework\TestCase;

final class RevisionCalculatorTest extends TestCase
{
    private const PINNED = 'bd652502';

    public function testPinnedRevision(): void
    {
        self::assertSame(self::PINNED, $this->revision($this->slo()));
    }

    public function testMessengerRevisionIncludesTheSortedFailureTransports(): void
    {
        $calculator = new RevisionCalculator();
        $slo = $this->slo(pointId: 'App\\Message\\Pay', type: PointType::MessengerMessage);

        $none = $calculator->revision($slo, ObjectiveKind::Availability, [])->value;
        $failed = $calculator->revision($slo, ObjectiveKind::Availability, ['failed', 'dead'])->value;

        self::assertSame('a098f2a6', $failed);
        self::assertNotSame($none, $failed);
        self::assertSame($failed, $calculator->revision($slo, ObjectiveKind::Availability, ['dead', 'failed'])->value);
        self::assertNotSame($failed, $calculator->revision($slo, ObjectiveKind::Availability, ['failed'])->value);
    }

    public function testFailureTransportsDoNotChangeOtherPointTypes(): void
    {
        $calculator = new RevisionCalculator();

        self::assertSame(self::PINNED, $calculator->revision($this->slo(), ObjectiveKind::Latency, ['failed'])->value);
    }

    public function testUndeclaredKindIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new RevisionCalculator())->revision($this->slo(), ObjectiveKind::Combined, []);
    }

    public function testThresholdChangesRevision(): void
    {
        self::assertNotSame($this->revision($this->slo()), $this->revision($this->slo(threshold: '100ms')));
    }

    public function testKindChangesRevision(): void
    {
        $slo = $this->slo();
        $calculator = new RevisionCalculator();

        self::assertNotSame(
            $calculator->revision($slo, ObjectiveKind::Latency, [])->value,
            $calculator->revision($slo, ObjectiveKind::Availability, [])->value,
        );
    }

    public function testIncludeErrorsChangesRevision(): void
    {
        self::assertNotSame($this->revision($this->slo()), $this->revision($this->slo(includeErrors: true)));
    }

    public function testClassificationChangesRevision(): void
    {
        $base = $this->revision($this->slo());

        self::assertNotSame($base, $this->revision($this->slo(classification: new Classification(StatusSet::fromList(['5xx']), StatusSet::fromList([]), false, false))));
        self::assertNotSame($base, $this->revision($this->slo(classification: new Classification(StatusSet::fromList(['5xx']), StatusSet::fromList([]), true, true))));
        self::assertNotSame($base, $this->revision($this->slo(classification: new Classification(StatusSet::fromList(['5xx']), StatusSet::fromList([429]), true, false))));
        self::assertNotSame($base, $this->revision($this->slo(classification: new Classification(StatusSet::fromList(['500']), StatusSet::fromList([]), true, false))));
    }

    public function testPointTypeChangesRevision(): void
    {
        self::assertNotSame($this->revision($this->slo()), $this->revision($this->slo(type: PointType::Operation)));
    }

    public function testNonSemanticFieldsDoNotChangeRevision(): void
    {
        $base = $this->revision($this->slo());

        self::assertSame($base, $this->revision($this->slo(target: 99.9)));
        self::assertSame($base, $this->revision($this->slo(window: '7d')));
        self::assertSame($base, $this->revision($this->slo(name: 'other')));
        self::assertSame($base, $this->revision($this->slo(pointId: 'another_route')));
        self::assertSame($base, $this->revision($this->slo(strategy: StrategyPreference::Dedicated)));
    }

    public function testStatusOrderDoesNotChangeRevision(): void
    {
        self::assertSame(
            $this->revision($this->slo(classification: $this->classification(['5xx', 429]))),
            $this->revision($this->slo(classification: $this->classification([429, '5xx']))),
        );
    }

    public function testRedundantStatusDeclarationDoesNotChangeRevision(): void
    {
        self::assertSame(
            $this->revision($this->slo(classification: $this->classification(['5xx']))),
            $this->revision($this->slo(classification: $this->classification(['5xx', '500']))),
        );
    }

    public function testAvailabilityRevisionIgnoresThreshold(): void
    {
        $calculator = new RevisionCalculator();

        self::assertSame(
            $calculator->revision($this->slo(), ObjectiveKind::Availability, [])->value,
            $calculator->revision($this->slo(threshold: '100ms'), ObjectiveKind::Availability, [])->value,
        );
    }

    /**
     * @param list<int|string> $bad
     */
    private function classification(array $bad): Classification
    {
        return new Classification(StatusSet::fromList($bad), StatusSet::fromList([]), true, false);
    }

    private function revision(SloDefinition $slo): string
    {
        return (new RevisionCalculator())->revision($slo, ObjectiveKind::Latency, [])->value;
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-string $pointId
     */
    private function slo(
        string $threshold = '200ms',
        float $target = 99.0,
        string $window = '30d',
        string $name = 'payment',
        string $pointId = 'payment_create',
        bool $includeErrors = false,
        ?Classification $classification = null,
        PointType $type = PointType::HttpRoute,
        StrategyPreference $strategy = StrategyPreference::Auto,
    ): SloDefinition {
        return new SloDefinition(
            SloName::fromString($name),
            $type,
            [PointId::of($type, $pointId)],
            [
                Objective::availability(Target::fromPercent($target)),
                Objective::latency(LatencyThreshold::fromString($threshold), Target::fromPercent($target), $includeErrors),
            ],
            Window::fromString($window),
            $classification ?? Classification::defaults(),
            $strategy,
        );
    }
}
