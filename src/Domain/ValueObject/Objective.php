<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\ValueObject;

use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;

final readonly class Objective
{
    private function __construct(
        public ObjectiveKind $kind,
        public Target $target,
        public ?LatencyThreshold $threshold,
        public bool $includeErrors,
    ) {
    }

    public static function availability(Target $target): self
    {
        return new self(ObjectiveKind::Availability, $target, null, false);
    }

    public static function latency(LatencyThreshold $threshold, Target $target, bool $includeErrors = false): self
    {
        return new self(ObjectiveKind::Latency, $target, $threshold, $includeErrors);
    }

    public static function combined(LatencyThreshold $threshold, Target $target): self
    {
        return new self(ObjectiveKind::Combined, $target, $threshold, false);
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind
            && $this->target->percent === $other->target->percent
            && $this->threshold?->milliseconds === $other->threshold?->milliseconds
            && $this->includeErrors === $other->includeErrors;
    }
}
