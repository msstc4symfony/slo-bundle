<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\ValueObject;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\Enum\DeliveryStatus;
use Msstc4Symfony\SloBundle\Domain\Enum\Outcome;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;

final readonly class Observation
{
    private function __construct(
        public PointType $pointType,
        public ?int $httpStatus,
        public ?DeliveryStatus $deliveryStatus,
        public bool $onFailureTransport,
        public ?Outcome $outcome,
        public ?float $durationSeconds,
    ) {
        if ($durationSeconds !== null && (!is_finite($durationSeconds) || $durationSeconds < 0.0)) {
            throw new InvalidArgumentException(\sprintf('Invalid duration "%s": expected a finite non-negative number of seconds.', var_export($durationSeconds, true)));
        }
    }

    /**
     * @throws InvalidArgumentException for a status outside 100..599 or a negative or non-finite duration
     */
    public static function http(int $status, float $durationSeconds): self
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException(\sprintf('Invalid HTTP status "%d".', $status));
        }

        return new self(PointType::HttpRoute, $status, null, false, null, $durationSeconds);
    }

    /**
     * @throws InvalidArgumentException for a negative or non-finite duration
     */
    public static function delivery(DeliveryStatus $status, bool $onFailureTransport, ?float $durationSeconds): self
    {
        return new self(PointType::MessengerMessage, null, $status, $onFailureTransport, null, $durationSeconds);
    }

    /**
     * @throws InvalidArgumentException for a point type without outcomes, a failure-transport flag outside scheduled_task, or a negative or non-finite duration
     */
    public static function outcome(PointType $type, Outcome $outcome, float $durationSeconds, bool $onFailureTransport = false): self
    {
        if (!\in_array($type, [PointType::LogicAction, PointType::Operation, PointType::ScheduledTask], true)) {
            throw new InvalidArgumentException(\sprintf('Point type "%s" has no outcome observations: expected logic_action, operation or scheduled_task.', $type->value));
        }
        if ($onFailureTransport && $type !== PointType::ScheduledTask) {
            throw new InvalidArgumentException(\sprintf('Point type "%s" has no failure transport: only scheduled_task outcomes carry the flag.', $type->value));
        }

        return new self($type, null, null, $onFailureTransport, $outcome, $durationSeconds);
    }
}
