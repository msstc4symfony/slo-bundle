<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Domain\Service;

use Closure;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\DeliveryStatus;
use Msstc4Symfony\SloBundle\Domain\Enum\EventResult;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\Outcome;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Msstc4Symfony\SloBundle\Domain\Service\EventClassifier;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Classification;
use Msstc4Symfony\SloBundle\Domain\ValueObject\LatencyThreshold;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Observation;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Domain\ValueObject\StatusSet;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Target;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Window;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EventClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{ObjectiveKind, Closure(): Observation, ?EventResult, 3?: array{classification?: Classification, includeErrors?: bool}}>
     */
    public static function provideCases(): iterable
    {
        $good = EventResult::Good;
        $bad = EventResult::Bad;
        $av = ObjectiveKind::Availability;
        $lat = ObjectiveKind::Latency;
        $comb = ObjectiveKind::Combined;

        foreach ([[200, $good], [400, $good], [404, $good], [429, $good], [500, $bad], [503, $bad]] as [$status, $expected]) {
            yield \sprintf('http %d availability', $status) => [$av, static fn (): Observation => Observation::http($status, 0.01), $expected];
        }

        yield 'http 200 latency fast' => [$lat, static fn (): Observation => Observation::http(200, 0.150), $good];
        yield 'http 200 latency slow' => [$lat, static fn (): Observation => Observation::http(200, 0.250), $bad];
        yield 'http 200 latency at threshold' => [$lat, static fn (): Observation => Observation::http(200, 0.200), $good];
        yield 'http 500 latency not eligible' => [$lat, static fn (): Observation => Observation::http(500, 0.010), null];
        yield 'http 500 latency include errors' => [$lat, static fn (): Observation => Observation::http(500, 0.010), $good, ['includeErrors' => true]];
        yield 'http 500 slow latency include errors' => [$lat, static fn (): Observation => Observation::http(500, 0.900), $bad, ['includeErrors' => true]];

        yield 'http 500 combined' => [$comb, static fn (): Observation => Observation::http(500, 0.010), $bad];
        yield 'http 200 slow combined' => [$comb, static fn (): Observation => Observation::http(200, 0.250), $bad];
        yield 'http 200 fast combined' => [$comb, static fn (): Observation => Observation::http(200, 0.100), $good];

        $ineligible429 = ['classification' => new Classification(StatusSet::fromList(['5xx']), StatusSet::fromList([429]), true, false)];
        yield 'http 429 ineligible availability' => [$av, static fn (): Observation => Observation::http(429, 0.01), null, $ineligible429];
        yield 'http 429 ineligible latency' => [$lat, static fn (): Observation => Observation::http(429, 0.01), null, $ineligible429];
        yield 'http 429 ineligible combined' => [$comb, static fn (): Observation => Observation::http(429, 0.01), null, $ineligible429];
        yield 'http 200 with ineligible set' => [$av, static fn (): Observation => Observation::http(200, 0.01), $good, $ineligible429];

        $custom = ['classification' => new Classification(StatusSet::fromList(['500-502', 429]), StatusSet::fromList([]), true, false)];
        yield 'custom bad 429' => [$av, static fn (): Observation => Observation::http(429, 0.01), $bad, $custom];
        yield 'custom 503 good' => [$av, static fn (): Observation => Observation::http(503, 0.01), $good, $custom];

        $equalBounds = ['classification' => new Classification(StatusSet::fromList(['5xx', '500-599']), StatusSet::fromList([]), true, false)];
        yield 'equal-bound bad items 503' => [$av, static fn (): Observation => Observation::http(503, 0.01), $bad, $equalBounds];
        yield 'equal-bound bad items 200' => [$av, static fn (): Observation => Observation::http(200, 0.01), $good, $equalBounds];

        $attempts = ['classification' => new Classification(StatusSet::fromList(['5xx']), StatusSet::fromList([]), true, true)];
        foreach ([false, true] as $countAttempts) {
            $opts = $countAttempts ? $attempts : [];
            $label = $countAttempts ? 'attempts' : 'default';
            yield "delivery handled $label" => [$av, static fn (): Observation => Observation::delivery(DeliveryStatus::Handled, false, 0.01), $good, $opts];
            yield "delivery failed $label" => [$av, static fn (): Observation => Observation::delivery(DeliveryStatus::Failed, false, 0.01), $bad, $opts];
            yield "delivery retried $label" => [$av, static fn (): Observation => Observation::delivery(DeliveryStatus::Retried, false, 0.01), $countAttempts ? $bad : null, $opts];
            yield "delivery handled failure transport $label" => [$av, static fn (): Observation => Observation::delivery(DeliveryStatus::Handled, true, 0.01), null, $opts];
            yield "delivery failed failure transport $label" => [$av, static fn (): Observation => Observation::delivery(DeliveryStatus::Failed, true, 0.01), null, $opts];
            yield "delivery retried failure transport $label" => [$av, static fn (): Observation => Observation::delivery(DeliveryStatus::Retried, true, 0.01), null, $opts];
        }

        yield 'delivery handled latency fast' => [$lat, static fn (): Observation => Observation::delivery(DeliveryStatus::Handled, false, 0.1), $good];
        yield 'delivery handled latency slow' => [$lat, static fn (): Observation => Observation::delivery(DeliveryStatus::Handled, false, 0.5), $bad];
        yield 'delivery failed latency not eligible' => [$lat, static fn (): Observation => Observation::delivery(DeliveryStatus::Failed, false, 0.01), null];
        yield 'delivery retried attempts latency not eligible' => [$lat, static fn (): Observation => Observation::delivery(DeliveryStatus::Retried, false, 0.01), null, $attempts];
        yield 'delivery handled null duration latency' => [$lat, static fn (): Observation => Observation::delivery(DeliveryStatus::Handled, false, null), null];
        yield 'delivery handled null duration combined' => [$comb, static fn (): Observation => Observation::delivery(DeliveryStatus::Handled, false, null), null];
        yield 'delivery failed null duration combined' => [$comb, static fn (): Observation => Observation::delivery(DeliveryStatus::Failed, false, null), $bad];
        yield 'delivery handled null duration availability' => [$av, static fn (): Observation => Observation::delivery(DeliveryStatus::Handled, false, null), $good];
        yield 'delivery failed combined' => [$comb, static fn (): Observation => Observation::delivery(DeliveryStatus::Failed, false, 0.01), $bad];

        yield 'outcome success' => [$av, static fn (): Observation => Observation::outcome(PointType::LogicAction, Outcome::Success, 0.01), $good];
        yield 'outcome error' => [$av, static fn (): Observation => Observation::outcome(PointType::LogicAction, Outcome::Error, 0.01), $bad];
        yield 'outcome expected failure good' => [$av, static fn (): Observation => Observation::outcome(PointType::LogicAction, Outcome::ExpectedFailure, 0.01), $good];
        yield 'outcome expected failure bad' => [$av, static fn (): Observation => Observation::outcome(PointType::LogicAction, Outcome::ExpectedFailure, 0.01), $bad, ['classification' => new Classification(StatusSet::fromList(['5xx']), StatusSet::fromList([]), false, false)]];
        yield 'outcome expected failure latency eligible' => [$lat, static fn (): Observation => Observation::outcome(PointType::LogicAction, Outcome::ExpectedFailure, 0.1), $good];
        // Spec 4.4: an outcome point's latency covers every non-error run, whatever expectedFailureIsGood says.
        yield 'outcome expected failure not good latency' => [$lat, static fn (): Observation => Observation::outcome(PointType::LogicAction, Outcome::ExpectedFailure, 0.1), $good, ['classification' => new Classification(StatusSet::fromList(['5xx']), StatusSet::fromList([]), false, false)]];
        yield 'outcome expected failure not good slow latency' => [$lat, static fn (): Observation => Observation::outcome(PointType::LogicAction, Outcome::ExpectedFailure, 0.5), $bad, ['classification' => new Classification(StatusSet::fromList(['5xx']), StatusSet::fromList([]), false, false)]];
        yield 'operation expected failure not good latency' => [$lat, static fn (): Observation => Observation::outcome(PointType::Operation, Outcome::ExpectedFailure, 0.1), $good, ['classification' => new Classification(StatusSet::fromList(['5xx']), StatusSet::fromList([]), false, false)]];
        yield 'outcome expected failure not good combined' => [$comb, static fn (): Observation => Observation::outcome(PointType::LogicAction, Outcome::ExpectedFailure, 0.1), $bad, ['classification' => new Classification(StatusSet::fromList(['5xx']), StatusSet::fromList([]), false, false)]];
        yield 'outcome error latency not eligible' => [$lat, static fn (): Observation => Observation::outcome(PointType::LogicAction, Outcome::Error, 0.1), null];
        yield 'outcome success slow latency' => [$lat, static fn (): Observation => Observation::outcome(PointType::LogicAction, Outcome::Success, 0.5), $bad];
        yield 'outcome error combined' => [$comb, static fn (): Observation => Observation::outcome(PointType::LogicAction, Outcome::Error, 0.01), $bad];
        yield 'outcome success fast combined' => [$comb, static fn (): Observation => Observation::outcome(PointType::LogicAction, Outcome::Success, 0.01), $good];
    }

    /**
     * @param Closure(): Observation $observation
     * @param array{classification?: Classification, includeErrors?: bool} $options
     */
    #[DataProvider('provideCases')]
    public function testClassify(ObjectiveKind $kind, Closure $observation, ?EventResult $expected, array $options = []): void
    {
        $event = $observation();
        $slo = $this->slo($event->pointType, $options['classification'] ?? Classification::defaults(), $options['includeErrors'] ?? false);
        $objective = $slo->objective($kind);
        self::assertNotNull($objective);

        $result = (new EventClassifier())->classify($slo, $objective, $event);

        self::assertSame($expected, $result);
    }

    public function testScheduledTaskOnFailureTransportIsIneligible(): void
    {
        $slo = $this->slo(PointType::ScheduledTask, Classification::defaults(), false, '2s');
        $onTransport = Observation::outcome(PointType::ScheduledTask, Outcome::Error, 0.1, true);

        foreach ([ObjectiveKind::Availability, ObjectiveKind::Latency, ObjectiveKind::Combined] as $kind) {
            self::assertNull($this->classify($slo, $kind, $onTransport));
        }
    }

    public function testScheduledTaskOutcomes(): void
    {
        $slo = $this->slo(PointType::ScheduledTask, Classification::defaults(), false, '2s');
        $notGood = $this->slo(PointType::ScheduledTask, new Classification(StatusSet::fromList(['5xx']), StatusSet::fromList([]), false, false), false, '2s');
        $error = Observation::outcome(PointType::ScheduledTask, Outcome::Error, 0.1);

        self::assertSame(EventResult::Bad, $this->classify($slo, ObjectiveKind::Availability, $error));
        self::assertNull($this->classify($slo, ObjectiveKind::Latency, $error));
        self::assertSame(EventResult::Bad, $this->classify($slo, ObjectiveKind::Latency, Observation::outcome(PointType::ScheduledTask, Outcome::Success, 3.0)));
        $expected = Observation::outcome(PointType::ScheduledTask, Outcome::ExpectedFailure, 0.5);
        self::assertSame(EventResult::Bad, $this->classify($notGood, ObjectiveKind::Availability, $expected));
        self::assertSame(EventResult::Good, $this->classify($notGood, ObjectiveKind::Latency, $expected));
    }

    private function classify(SloDefinition $slo, ObjectiveKind $kind, Observation $observation): ?EventResult
    {
        $objective = $slo->objective($kind);
        self::assertNotNull($objective);

        return (new EventClassifier())->classify($slo, $objective, $observation);
    }

    private function slo(PointType $type, Classification $classification, bool $includeErrors, string $latency = '200ms'): SloDefinition
    {
        $threshold = LatencyThreshold::fromString($latency);
        $target = Target::fromPercent(99.0);

        return new SloDefinition(
            SloName::fromString('payment'),
            $type,
            [PointId::of($type, 'payment_create')],
            [
                Objective::availability($target),
                Objective::latency($threshold, $target, $includeErrors),
                Objective::combined($threshold, $target),
            ],
            Window::fromString('30d'),
            $classification,
            StrategyPreference::Auto,
        );
    }
}
