<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\ValueObject;

use InvalidArgumentException;

/**
 * How long a scheduled-task run may take before it counts as unfinished.
 */
final readonly class Deadline
{
    private const PATTERN = '/^(?:([0-9]{1,9})ms|([0-9]{1,9})(?:\.([0-9]{1,12}))?s|([0-9]{1,6})m|([0-9]{1,5})h)$/D';

    /** Same bound as LatencyThreshold, so a threshold is always a valid deadline. */
    private const MAX_MILLISECONDS = 999_999_999;

    private const MILLISECONDS_PER_MINUTE = 60_000;

    private const MILLISECONDS_PER_HOUR = 3_600_000;

    /**
     * @param positive-int $milliseconds
     */
    private function __construct(public int $milliseconds)
    {
    }

    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value, $m, PREG_UNMATCHED_AS_NULL) !== 1) {
            throw new InvalidArgumentException(\sprintf('Invalid deadline "%s": expected <int>ms, <decimal>s, <int>m or <int>h.', $value));
        }

        $milliseconds = match (true) {
            $m[1] !== null => (int) $m[1],
            $m[2] !== null => self::fromSeconds($value, (int) $m[2], rtrim($m[3] ?? '', '0')),
            $m[4] !== null => (int) $m[4] * self::MILLISECONDS_PER_MINUTE,
            default => (int) $m[5] * self::MILLISECONDS_PER_HOUR,
        };

        if ($milliseconds < 1) {
            throw new InvalidArgumentException(\sprintf('Invalid deadline "%s": must be positive.', $value));
        }
        if ($milliseconds > self::MAX_MILLISECONDS) {
            throw new InvalidArgumentException(\sprintf('Invalid deadline "%s": at most %dms.', $value, self::MAX_MILLISECONDS));
        }

        return new self($milliseconds);
    }

    public static function fromThreshold(LatencyThreshold $threshold): self
    {
        return new self($threshold->milliseconds);
    }

    /**
     * @internal restores a compiled deadline, not covered by semantic versioning
     */
    public static function fromMilliseconds(int $milliseconds): self
    {
        if ($milliseconds < 1 || $milliseconds > self::MAX_MILLISECONDS) {
            throw new InvalidArgumentException(\sprintf('Invalid deadline %dms: expected 1 to %d.', $milliseconds, self::MAX_MILLISECONDS));
        }

        return new self($milliseconds);
    }

    public function seconds(): float
    {
        return $this->milliseconds / 1000;
    }

    /**
     * Largest exact unit, always a valid PromQL duration.
     *
     * @return non-empty-string
     */
    public function toString(): string
    {
        return match (true) {
            $this->milliseconds % self::MILLISECONDS_PER_HOUR === 0 => \sprintf('%dh', intdiv($this->milliseconds, self::MILLISECONDS_PER_HOUR)),
            $this->milliseconds % self::MILLISECONDS_PER_MINUTE === 0 => \sprintf('%dm', intdiv($this->milliseconds, self::MILLISECONDS_PER_MINUTE)),
            $this->milliseconds % 1000 === 0 => \sprintf('%ds', intdiv($this->milliseconds, 1000)),
            default => \sprintf('%dms', $this->milliseconds),
        };
    }

    private static function fromSeconds(string $value, int $seconds, string $fraction): int
    {
        if (\strlen($fraction) > 3) {
            throw new InvalidArgumentException(\sprintf('Invalid deadline "%s": finer than 1ms.', $value));
        }

        return $seconds * 1000 + (int) str_pad($fraction, 3, '0');
    }
}
