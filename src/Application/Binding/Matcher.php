<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Binding;

use InvalidArgumentException;

/**
 * One label matcher of a series selector. The value is kept raw (the pattern for the regex operators); the
 * Prometheus adapter renders and quotes it.
 *
 * @internal
 */
final readonly class Matcher
{
    private const LABEL_PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/D';

    /**
     * @param non-empty-string $label
     */
    private function __construct(
        public string $label,
        public MatchOperator $operator,
        public string $value,
    ) {
        if (preg_match(self::LABEL_PATTERN, $label) !== 1) {
            throw new InvalidArgumentException(\sprintf('Invalid label name "%s" in a matcher.', $label));
        }
    }

    /**
     * @param non-empty-string $label
     */
    public static function equals(string $label, string $value): self
    {
        return new self($label, MatchOperator::Equals, $value);
    }

    /**
     * @param non-empty-string $label
     */
    public static function notEquals(string $label, string $value): self
    {
        return new self($label, MatchOperator::NotEquals, $value);
    }

    /**
     * @param non-empty-string $label
     */
    public static function regex(string $label, string $pattern): self
    {
        return new self($label, MatchOperator::Regex, $pattern);
    }

    /**
     * @param non-empty-string $label
     */
    public static function notRegex(string $label, string $pattern): self
    {
        return new self($label, MatchOperator::NotRegex, $pattern);
    }
}
