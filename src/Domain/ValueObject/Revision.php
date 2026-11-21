<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\ValueObject;

use InvalidArgumentException;

/**
 * @internal
 */
final readonly class Revision
{
    private const PATTERN = '/^[0-9a-f]{8}$/D';

    /**
     * @param non-empty-string $value
     */
    private function __construct(public string $value)
    {
    }

    public static function fromHex(string $hex): self
    {
        if (preg_match(self::PATTERN, $hex) !== 1) {
            throw new InvalidArgumentException(\sprintf('Invalid revision "%s": expected 8 lowercase hex digits.', $hex));
        }

        return new self($hex);
    }
}
