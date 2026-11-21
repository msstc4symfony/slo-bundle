<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Binding;

use InvalidArgumentException;

/**
 * One PromQL label matcher. The value is kept raw (regex source for the regex operators); toPromQl() quotes it.
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

    /**
     * Escapes a literal for use inside an RE2 pattern (PromQL regex matchers); any ASCII punctuation may be escaped.
     */
    public static function escapeRegex(string $literal): string
    {
        return preg_replace('/[\x21-\x2F\x3A-\x40\x5B-\x5E\x60\x7B-\x7E]/', '\\\\$0', $literal) ?? $literal;
    }

    /**
     * @return non-empty-string
     */
    public function toPromQl(): string
    {
        return $this->label . $this->operator->value . '"' . strtr($this->value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n']) . '"';
    }
}
