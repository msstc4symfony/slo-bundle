<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Domain\Entity;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Classification;
use Msstc4Symfony\SloBundle\Domain\ValueObject\LatencyThreshold;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Domain\ValueObject\StatusSet;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Target;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Window;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SloDefinitionTest extends TestCase
{
    public function testGetters(): void
    {
        $definition = $this->definition();

        self::assertSame('payment', $definition->name()->value);
        self::assertSame(PointType::HttpRoute, $definition->pointType());
        self::assertCount(1, $definition->points());
        self::assertCount(2, $definition->objectives());
        self::assertSame(2592000, $definition->window()->seconds());
        self::assertEquals(Classification::defaults(), $definition->classification());
        self::assertSame(StrategyPreference::Auto, $definition->strategy());
        self::assertSame(ObjectiveKind::Latency, $definition->objective(ObjectiveKind::Latency)?->kind);
        self::assertNull($definition->objective(ObjectiveKind::Combined));
    }

    public function testRejectsMixedPointTypes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->definition(points: [$this->point('a'), PointId::of(PointType::Operation, 'b')]);
    }

    public function testRejectsDuplicatePoints(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->definition(points: [$this->point('a'), $this->point('a')]);
    }

    public function testRejectsDuplicateKinds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->definition(objectives: [$this->availability(), $this->availability()]);
    }

    public function testRejectsEmptyPoints(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // Reason: asserting the runtime guard against a contract violation.
        // @phpstan-ignore argument.type
        $this->definition(points: []);
    }

    public function testRejectsEmptyObjectives(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // Reason: asserting the runtime guard against a contract violation.
        // @phpstan-ignore argument.type
        $this->definition(objectives: []);
    }

    public function testWithPointAppendsAndLeavesOriginalUntouched(): void
    {
        $original = $this->definition();
        $extended = $original->withPoint($this->point('b'));

        self::assertCount(1, $original->points());
        self::assertSame(['http_route:a', 'http_route:b'], array_map(static fn (PointId $p): string => $p->toString(), $extended->points()));
    }

    public function testWithPointRejectsDuplicate(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->definition()->withPoint($this->point('a'));
    }

    public function testWithPointRejectsOtherType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->definition()->withPoint(PointId::of(PointType::Operation, 'x'));
    }

    public function testIncludeErrorsIsAcceptedOnHttpRoutesOnly(): void
    {
        $latency = Objective::latency(LatencyThreshold::fromString('300ms'), Target::fromPercent(95.0), true);

        self::assertTrue($this->definition(objectives: [$latency])->objectives()[0]->includeErrors);

        $this->expectExceptionObject(new InvalidArgumentException('SLO "payment": includeErrors applies to http_route points only.'));
        $this->definition(pointType: PointType::Operation, points: [PointId::of(PointType::Operation, 'a')], objectives: [$latency]);
    }

    /**
     * @return iterable<string, array{PointType, Classification, non-empty-string}>
     */
    public static function provideIrrelevantClassification(): iterable
    {
        yield 'bad statuses on messenger' => [PointType::MessengerMessage, self::classification(['5xx', 429], []), 'badStatuses must keep the default on messenger_message points'];
        yield 'ineligible statuses on operation' => [PointType::Operation, self::classification(['5xx'], [404]), 'ineligibleStatuses must keep the default on operation points'];
        yield 'expected failure on http' => [PointType::HttpRoute, self::classification(['5xx'], [], false), 'expectedFailureIsGood must keep the default on http_route points'];
        yield 'expected failure on messenger' => [PointType::MessengerMessage, self::classification(['5xx'], [], false), 'expectedFailureIsGood must keep the default on messenger_message points'];
        yield 'count attempts on logic' => [PointType::LogicAction, self::classification(['5xx'], [], true, true), 'countAttempts must keep the default on logic_action points'];
        yield 'several at once' => [PointType::LogicAction, self::classification(['4xx'], [], true, true), 'badStatuses, countAttempts must keep the default'];
    }

    /**
     * @param non-empty-string $message
     */
    #[DataProvider('provideIrrelevantClassification')]
    public function testClassificationFieldsIrrelevantToThePointTypeMustBeDefaults(PointType $type, Classification $classification, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $this->definition(pointType: $type, points: [PointId::of($type, 'a')], classification: $classification);
    }

    public function testRelevantClassificationFieldsMayDiffer(): void
    {
        $http = $this->definition(classification: new Classification(StatusSet::fromList([429]), StatusSet::fromList([404]), true, false));
        $logic = $this->definition(pointType: PointType::LogicAction, points: [PointId::of(PointType::LogicAction, 'a')], classification: new Classification(StatusSet::fromList(['5xx']), StatusSet::fromList([]), false, false));
        $operation = $this->definition(pointType: PointType::Operation, points: [PointId::of(PointType::Operation, 'a')], classification: new Classification(StatusSet::fromList(['5xx']), StatusSet::fromList([]), false, false));
        $messenger = $this->definition(pointType: PointType::MessengerMessage, points: [PointId::of(PointType::MessengerMessage, 'a')], classification: new Classification(StatusSet::fromList(['5xx']), StatusSet::fromList([]), true, true));

        self::assertSame(['429'], $http->classification()->badStatuses->canonical());
        self::assertFalse($logic->classification()->expectedFailureIsGood);
        self::assertFalse($operation->classification()->expectedFailureIsGood);
        self::assertTrue($messenger->classification()->countAttempts);
    }

    public function testSameSemanticsIgnoresPointsOnly(): void
    {
        $base = $this->definition();

        self::assertTrue($base->sameSemanticsAs($this->definition(points: [$this->point('z')])));
        self::assertTrue($base->sameSemanticsAs($this->definition(objectives: [$this->latency(), $this->availability()])));
        self::assertFalse($base->sameSemanticsAs($this->definition(window: Window::fromString('7d'))));
        self::assertFalse($base->sameSemanticsAs($this->definition(strategy: StrategyPreference::Dedicated)));
        self::assertFalse($base->sameSemanticsAs($this->definition(classification: new Classification(StatusSet::fromList(['5xx']), StatusSet::fromList([429]), true, false))));
        self::assertFalse($base->sameSemanticsAs($this->definition(objectives: [$this->availability()])));
        self::assertFalse($base->sameSemanticsAs($this->definition(objectives: [
            Objective::availability(Target::fromPercent(99.5)),
            $this->latency(),
        ])));
        self::assertFalse($base->sameSemanticsAs($this->definition(name: 'other')));
        self::assertFalse($base->sameSemanticsAs($this->definition(pointType: PointType::Operation, points: [PointId::of(PointType::Operation, 'a')])));
    }

    /**
     * @param list<int|string> $bad
     * @param list<int|string> $ineligible
     */
    private static function classification(array $bad, array $ineligible, bool $expectedFailureIsGood = true, bool $countAttempts = false): Classification
    {
        return new Classification(StatusSet::fromList($bad), StatusSet::fromList($ineligible), $expectedFailureIsGood, $countAttempts);
    }

    /**
     * @param non-empty-string $id
     */
    private function point(string $id): PointId
    {
        return PointId::of(PointType::HttpRoute, $id);
    }

    private function availability(): Objective
    {
        return Objective::availability(Target::fromPercent(99.9));
    }

    private function latency(): Objective
    {
        return Objective::latency(LatencyThreshold::fromString('300ms'), Target::fromPercent(95.0));
    }

    /**
     * @param non-empty-string $name
     * @param non-empty-list<PointId>|null $points
     * @param non-empty-list<Objective>|null $objectives
     */
    private function definition(
        string $name = 'payment',
        PointType $pointType = PointType::HttpRoute,
        ?array $points = null,
        ?array $objectives = null,
        ?Window $window = null,
        ?Classification $classification = null,
        StrategyPreference $strategy = StrategyPreference::Auto,
    ): SloDefinition {
        return new SloDefinition(
            SloName::fromString($name),
            $pointType,
            $points ?? [$this->point('a')],
            $objectives ?? [$this->availability(), $this->latency()],
            $window ?? Window::fromString('30d'),
            $classification ?? Classification::defaults(),
            $strategy,
        );
    }
}
