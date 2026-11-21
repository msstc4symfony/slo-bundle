<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Prometheus;

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
    private const DURATION_UNITS = ['w' => 604800, 'd' => 86400];

    private const HOUR = 3600;

    /**
     * The largest exact unit among w, d and h (PromQL has no month): 1mo → 30d, 2w → 2w, 36h → 36h.
     *
     * @return non-empty-string
     */
    public static function window(Window $window): string
    {
        $seconds = $window->seconds();
        foreach (self::DURATION_UNITS as $unit => $unitSeconds) {
            if ($seconds % $unitSeconds === 0) {
                return \sprintf('%d%s', intdiv($seconds, $unitSeconds), $unit);
            }
        }

        return \sprintf('%dh', intdiv($seconds, self::HOUR));
    }

    /**
     * Alternation over the `status` label values: a class "5xx" becomes "5..", a range lists every code.
     */
    public static function statusPattern(StatusSet $statuses): string
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

        return implode('|', $parts);
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

        return $matcher->label . $operator . '"' . strtr($matcher->value, ['\\' => '\\\\', '"' => '\\"', "\n" => '\\n']) . '"';
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
}
