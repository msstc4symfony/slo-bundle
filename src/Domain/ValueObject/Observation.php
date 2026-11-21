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

    public static function http(int $status, float $durationSeconds): self
    {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException(\sprintf('Invalid HTTP status "%d".', $status));
        }

        return new self(PointType::HttpRoute, $status, null, false, null, $durationSeconds);
    }

    public static function delivery(DeliveryStatus $status, bool $onFailureTransport, ?float $durationSeconds): self
    {
        return new self(PointType::MessengerMessage, null, $status, $onFailureTransport, null, $durationSeconds);
    }

    public static function outcome(PointType $type, Outcome $outcome, float $durationSeconds): self
    {
        if ($type !== PointType::LogicAction && $type !== PointType::Operation) {
            throw new InvalidArgumentException(\sprintf('Point type "%s" has no outcome observations: expected logic_action or operation.', $type->value));
        }

        return new self($type, null, null, false, $outcome, $durationSeconds);
    }
}
