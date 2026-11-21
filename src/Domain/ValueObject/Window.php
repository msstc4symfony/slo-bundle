<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\ValueObject;

use InvalidArgumentException;

final readonly class Window
{
    private const MIN_SECONDS = 3600;

    private const MAX_SECONDS = 7776000;

    private const UNIT_SECONDS = ['h' => 3600, 'd' => 86400, 'w' => 604800, 'mo' => 2592000];

    private const PATTERN = '/^([1-9][0-9]{0,5})(h|d|w|mo)$/D';

    /**
     * @param positive-int $seconds
     * @param non-empty-string $declared
     */
    private function __construct(private int $seconds, private string $declared)
    {
    }

    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value, $m) !== 1) {
            throw new InvalidArgumentException(\sprintf('Invalid SLO window "%s": expected <N>h|d|w|mo, e.g. 30d.', $value));
        }

        $seconds = (int) $m[1] * self::UNIT_SECONDS[$m[2]];
        if ($seconds < self::MIN_SECONDS || $seconds > self::MAX_SECONDS) {
            throw new InvalidArgumentException(\sprintf('Invalid SLO window "%s": must be between 1h and 90d.', $value));
        }

        return new self($seconds, $value);
    }

    /**
     * @return positive-int
     */
    public function seconds(): int
    {
        return $this->seconds;
    }

    /**
     * @return non-empty-string
     */
    public function toString(): string
    {
        return $this->declared;
    }

    /**
     * @return non-empty-string
     */
    public function toPromQl(): string
    {
        foreach (['w' => 604800, 'd' => 86400] as $unit => $unitSeconds) {
            if ($this->seconds % $unitSeconds === 0) {
                return \sprintf('%d%s', intdiv($this->seconds, $unitSeconds), $unit);
            }
        }

        return \sprintf('%dh', intdiv($this->seconds, 3600));
    }
}
