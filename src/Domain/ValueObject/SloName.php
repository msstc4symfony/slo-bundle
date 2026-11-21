<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\ValueObject;

use InvalidArgumentException;

final readonly class SloName
{
    private const PATTERN = '/^[a-z][a-z0-9_.-]{0,62}$/D';

    /**
     * @param non-empty-string $value
     */
    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if (preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidArgumentException(\sprintf('Invalid SLO name "%s": expected %s.', $value, self::PATTERN));
        }

        return new self($value);
    }
}
