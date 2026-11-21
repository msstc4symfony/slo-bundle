<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Prometheus;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Binding\Matcher;
use Msstc4Symfony\SloBundle\Application\Binding\MatchOperator;
use Msstc4Symfony\SloBundle\Domain\ValueObject\StatusSet;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Window;

/**
 * PromQL and RE2 syntax of the domain values: durations, status patterns, label matchers.
 *
 * @internal
 */
final class PromQlRenderer
{
    private const DURATION_UNITS = ['w' => 604800, 'd' => 86400, 'h' => 3600, 'm' => 60];

    /**
     * The largest exact unit among w, d and h (PromQL has no month): 1mo → 30d, 2w → 2w, 36h → 36h.
     *
     * @return non-empty-string
     */
    public static function window(Window $window): string
    {
        return self::duration($window->seconds());
    }

    /**
     * The largest exact unit among w, d, h and m, else seconds: 300 → 5m, 259200 → 3d.
     *
     * @param positive-int $seconds
     *
     * @return non-empty-string
     */
    public static function duration(int $seconds): string
    {
        foreach (self::DURATION_UNITS as $unit => $unitSeconds) {
            if ($seconds % $unitSeconds === 0) {
                return \sprintf('%d%s', intdiv($seconds, $unitSeconds), $unit);
            }
        }

        return \sprintf('%ds', $seconds);
    }

    /**
     * A PromQL float literal: the shortest decimal without exponent or trailing zeros.
     *
     * @return non-empty-string
     */
    public static function number(float $value): string
    {
        $formatted = rtrim(rtrim(\sprintf('%.10F', round($value, 10)), '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }

    /**
     * Alternation over the `status` label values: a class "5xx" becomes "5..", a range lists every code.
     *
     * @return non-empty-string|null null for an empty set, which matches no status
     */
    public static function statusPattern(StatusSet $statuses): ?string
    {
        $parts = [];
        foreach ($statuses->canonical() as $item) {
            if (str_ends_with($item, 'xx')) {
                $parts[] = $item[0] . '..';
                continue;
            }
            [$from, $to] = str_contains($item, '-') ? explode('-', $item, 2) : [$item, $item];
            for ($code = (int) $from; $code <= (int) $to; $code++) {
                $parts[] = (string) $code;
            }
        }

        $pattern = implode('|', $parts);

        return $pattern === '' ? null : $pattern;
    }

    /**
     * Escapes a literal for use inside an RE2 pattern (PromQL regex matchers); any ASCII punctuation may be escaped.
     */
    public static function escapeRegex(string $literal): string
    {
        return preg_replace('/[\x21-\x2F\x3A-\x40\x5B-\x5E\x60\x7B-\x7E]/', '\\\\$0', $literal) ?? $literal;
    }

    /**
     * @param list<string> $literals
     *
     * @return string empty for an empty list; callers pass at least one literal
     */
    public static function alternation(array $literals): string
    {
        return implode('|', array_map(self::escapeRegex(...), $literals));
    }

    /**
     * @return non-empty-string
     */
    public static function matcher(Matcher $matcher): string
    {
        $operator = match ($matcher->operator) {
            MatchOperator::Equals => '=',
            MatchOperator::NotEquals => '!=',
            MatchOperator::Regex => '=~',
            MatchOperator::NotRegex => '!~',
        };

        return $matcher->label . $operator . '"' . self::stringLiteral($matcher->value) . '"';
    }

    /**
     * @param list<Matcher> $matchers
     *
     * @return non-empty-string
     */
    public static function selector(array $matchers): string
    {
        return '{' . implode(',', array_map(self::matcher(...), $matchers)) . '}';
    }

    /**
     * Prometheus rejects a rule file with raw control characters or invalid UTF-8 in a string, which would silently drop
     * every alert of the file: control characters are escaped, invalid UTF-8 fails the generation.
     *
     * @throws InvalidArgumentException
     */
    private static function stringLiteral(string $value): string
    {
        if (preg_match('//u', $value) !== 1) {
            throw new InvalidArgumentException(\sprintf('PromQL label value "%s" is not valid UTF-8.', bin2hex($value)));
        }

        $escaped = strtr($value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n', "\r" => '\\r', "\t" => '\\t']);

        return preg_replace_callback('/[\x00-\x1F\x7F]/', static fn (array $m): string => \sprintf('\\x%02x', \ord($m[0])), $escaped) ?? $escaped;
    }
}
