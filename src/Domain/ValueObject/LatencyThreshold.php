<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\ValueObject;

use InvalidArgumentException;

final readonly class LatencyThreshold
{
    private const PATTERN = '/^(?:([0-9]{1,9})ms|([0-9]{1,9})(?:\.([0-9]{1,12}))?s)$/D';

    /** The canonical form must parse back: the ms form takes at most nine digits. */
    private const MAX_MILLISECONDS = 999_999_999;

    /**
     * @param positive-int $milliseconds
     */
    private function __construct(public int $milliseconds)
    {
    }

    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value, $m) !== 1) {
            throw new InvalidArgumentException(\sprintf('Invalid latency threshold "%s": expected <int>ms or <decimal>s.', $value));
        }

        if ($m[1] !== '') {
            $milliseconds = (int) $m[1];
        } else {
            $fraction = rtrim($m[3] ?? '', '0');
            if (\strlen($fraction) > 3) {
                throw new InvalidArgumentException(\sprintf('Invalid latency threshold "%s": finer than 1ms.', $value));
            }
            $milliseconds = (int) $m[2] * 1000 + (int) str_pad($fraction, 3, '0');
        }

        if ($milliseconds < 1) {
            throw new InvalidArgumentException(\sprintf('Invalid latency threshold "%s": must be positive.', $value));
        }
        if ($milliseconds > self::MAX_MILLISECONDS) {
            throw new InvalidArgumentException(\sprintf('Invalid latency threshold "%s": at most %dms.', $value, self::MAX_MILLISECONDS));
        }

        return new self($milliseconds);
    }

    public static function fromMilliseconds(int $milliseconds): self
    {
        if ($milliseconds < 1 || $milliseconds > self::MAX_MILLISECONDS) {
            throw new InvalidArgumentException(\sprintf('Invalid latency threshold %dms: expected 1 to %d.', $milliseconds, self::MAX_MILLISECONDS));
        }

        return new self($milliseconds);
    }

    public function seconds(): float
    {
        return $this->milliseconds / 1000;
    }

    /**
     * @return non-empty-string
     */
    public function toString(): string
    {
        return $this->milliseconds % 1000 === 0
            ? \sprintf('%ds', intdiv($this->milliseconds, 1000))
            : \sprintf('%dms', $this->milliseconds);
    }
}
