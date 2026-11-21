<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Evaluation\RecordEvent;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Input;
use Msstc4Symfony\SloBundle\Domain\Enum\DeliveryStatus;
use Msstc4Symfony\SloBundle\Domain\Enum\Outcome;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Observation;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use PHPUnit\Framework\TestCase;

final class InputTest extends TestCase
{
    public function testAcceptsAnObservationOfThePointType(): void
    {
        $input = new Input(PointId::of(PointType::Operation, 'payment.validation'), Observation::outcome(PointType::Operation, Outcome::Success, 0.1));

        self::assertSame(PointType::Operation, $input->observation->pointType);
    }

    public function testRejectsAnObservationOfAnotherPointType(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Observation of a "messenger_message" point recorded for point "http_route:App\Controller::create".');

        new Input(PointId::of(PointType::HttpRoute, 'App\Controller::create'), Observation::delivery(DeliveryStatus::Handled, false, 0.1));
    }
}
