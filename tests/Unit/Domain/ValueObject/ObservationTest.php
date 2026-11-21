<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\Enum\DeliveryStatus;
use Msstc4Symfony\SloBundle\Domain\Enum\Outcome;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Observation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ObservationTest extends TestCase
{
    public function testHttp(): void
    {
        $observation = Observation::http(503, 0.25);

        self::assertSame(PointType::HttpRoute, $observation->pointType);
        self::assertSame(503, $observation->httpStatus);
        self::assertSame(0.25, $observation->durationSeconds);
    }

    public function testDelivery(): void
    {
        $observation = Observation::delivery(DeliveryStatus::Failed, true, null);

        self::assertSame(PointType::MessengerMessage, $observation->pointType);
        self::assertSame(DeliveryStatus::Failed, $observation->deliveryStatus);
        self::assertTrue($observation->onFailureTransport);
        self::assertNull($observation->durationSeconds);
    }

    public function testOutcome(): void
    {
        $observation = Observation::outcome(PointType::LogicAction, Outcome::ExpectedFailure, 1.5);

        self::assertSame(PointType::LogicAction, $observation->pointType);
        self::assertSame(Outcome::ExpectedFailure, $observation->outcome);
        self::assertSame(1.5, $observation->durationSeconds);
    }

    public function testOperationOutcomeKeepsItsPointType(): void
    {
        self::assertSame(PointType::Operation, Observation::outcome(PointType::Operation, Outcome::Success, 0.1)->pointType);
    }

    /**
     * @return iterable<string, array{PointType}>
     */
    public static function provideNonOutcomeTypes(): iterable
    {
        yield 'http' => [PointType::HttpRoute];
        yield 'messenger' => [PointType::MessengerMessage];
    }

    #[DataProvider('provideNonOutcomeTypes')]
    public function testOutcomeRejectsPointTypesWithoutOutcomes(PointType $type): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"' . $type->value . '"');
        Observation::outcome($type, Outcome::Success, 0.1);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function provideBadDurations(): iterable
    {
        yield 'negative' => [-0.1];
        yield 'nan' => [NAN];
        yield 'inf' => [INF];
    }

    #[DataProvider('provideBadDurations')]
    public function testRejectsBadDuration(float $duration): void
    {
        $this->expectException(InvalidArgumentException::class);
        Observation::http(200, $duration);
    }

    public function testRejectsInvalidHttpStatus(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Observation::http(99, 0.1);
    }
}
