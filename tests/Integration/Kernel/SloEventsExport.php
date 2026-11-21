<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Kernel;

use PHPUnit\Framework\Assert;

/**
 * Reads symfony_slo_events_total from the Prometheus text exposition of metrics-bundle.
 */
final class SloEventsExport
{
    private const SAMPLE = '/^symfony_slo_events_total\{application="test",component="test",slo="([^"]+)",objective="([a-z]+)",revision="([0-9a-f]{8})",result="(good|bad)"\} (\S+)$/m';

    /**
     * @return array<string, int> "<slo>|<objective>|<result>" => value, sorted by key
     */
    public static function counts(string $body): array
    {
        $unparsed = array_filter(
            explode("\n", $body),
            static fn (string $line): bool => str_starts_with($line, 'symfony_slo_events_total') && preg_match(self::SAMPLE, $line) !== 1,
        );
        Assert::assertSame([], array_values($unparsed), 'every slo_events_total sample has the labels application, component, slo, objective, revision, result in this order');

        preg_match_all(self::SAMPLE, $body, $matches, PREG_SET_ORDER);
        $counts = [];
        foreach ($matches as $match) {
            $key = $match[1] . '|' . $match[2] . '|' . $match[4];
            $counts[$key] = ($counts[$key] ?? 0) + (int) $match[5];
        }
        ksort($counts);

        return $counts;
    }
}
