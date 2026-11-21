<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Grafana;

/**
 * Selectors of the metrics-bundle series (exported with the `symfony_` prefix) filtered by the dashboard variables.
 */
final readonly class BaseSeries
{
    public const RANGE = '$__rate_interval';

    private const FILTER = 'application="$application",component=~"$component"';

    /**
     * @param non-empty-string $metric metrics-bundle metric name without the exporter prefix
     * @param non-empty-string ...$matchers further label matchers
     *
     * @return non-empty-string
     */
    public static function of(string $metric, string ...$matchers): string
    {
        return \sprintf('symfony_%s{%s}', $metric, implode(',', [self::FILTER, ...$matchers]));
    }

    /**
     * @param non-empty-string $metric
     * @param non-empty-string ...$matchers
     *
     * @return non-empty-string
     */
    public static function rate(string $metric, string ...$matchers): string
    {
        return \sprintf('rate(%s[%s])', self::of($metric, ...$matchers), self::RANGE);
    }

    /**
     * @param non-empty-string $histogram metric name of the histogram without the `_bucket` suffix
     * @param non-empty-string $by label names kept next to `le`
     * @param non-empty-string ...$matchers
     *
     * @return non-empty-string
     */
    public static function quantile(float $quantile, string $histogram, string $by = 'le', string ...$matchers): string
    {
        return \sprintf('histogram_quantile(%s, sum by (%s) (%s))', rtrim(rtrim(\sprintf('%.2F', $quantile), '0'), '.'), $by, self::rate($histogram . '_bucket', ...$matchers));
    }
}
