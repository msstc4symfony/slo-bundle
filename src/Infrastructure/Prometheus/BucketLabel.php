<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Prometheus;

/**
 * The `le` label value Prometheus 3 stores for a classic-histogram bucket exposed by promphp.
 *
 * promphp renders the bucket with PHP's float-to-string cast; Prometheus 3 parses it and normalises it on ingestion
 * to Go's shortest 'g' form with ".0" appended to integral values (1 → "1.0", 1e6 → "1e+06").
 *
 * @internal
 */
final readonly class BucketLabel
{
    private const EXPONENT_FROM = 6;

    private const EXPONENT_BELOW = -4;

    private const RELATIVE_TOLERANCE = 1e-9;

    /**
     * @param non-empty-string $value
     */
    private function __construct(public float $exposed, public string $value)
    {
    }

    public static function fromBucket(float $bucket): self
    {
        $exposed = is_finite($bucket) ? (float) (string) $bucket : $bucket;

        return new self($exposed, self::format($exposed));
    }

    /**
     * Float noise only: 0.2504 does not match 250ms.
     *
     * @param positive-int $milliseconds
     */
    public function equalsMilliseconds(int $milliseconds): bool
    {
        return abs($this->exposed * 1000 - $milliseconds) <= self::RELATIVE_TOLERANCE * $milliseconds;
    }

    /**
     * @return non-empty-string
     */
    private static function format(float $value): string
    {
        if (is_nan($value)) {
            return 'NaN';
        }
        if (is_infinite($value)) {
            return $value > 0 ? '+Inf' : '-Inf';
        }
        if ($value === 0.0) {
            return '0.0';
        }

        $sign = $value < 0 ? '-' : '';
        [$digits, $exponent] = self::shortest(abs($value));
        $formatted = $exponent < self::EXPONENT_BELOW || $exponent >= self::EXPONENT_FROM
            ? self::scientific($digits, $exponent)
            : self::fixed($digits, $exponent);

        return $sign . (strpbrk($formatted, '.e') === false ? $formatted . '.0' : $formatted);
    }

    /**
     * @return array{non-empty-string, int} significant digits without trailing zeros and the decimal exponent
     */
    private static function shortest(float $value): array
    {
        $mantissa = '';
        $exponent = 0;
        for ($precision = 0; $precision <= 16; $precision++) {
            [$mantissa, $exponent] = explode('e', \sprintf('%.' . $precision . 'e', $value));
            if ((float) ($mantissa . 'e' . $exponent) === $value) {
                break;
            }
        }
        $digits = rtrim(str_replace('.', '', $mantissa), '0');

        return [$digits === '' ? '0' : $digits, (int) $exponent];
    }

    /**
     * @param non-empty-string $digits
     *
     * @return non-empty-string
     */
    private static function scientific(string $digits, int $exponent): string
    {
        $mantissa = \strlen($digits) > 1 ? $digits[0] . '.' . substr($digits, 1) : $digits;

        return \sprintf('%se%s%02d', $mantissa, $exponent < 0 ? '-' : '+', abs($exponent));
    }

    /**
     * @param non-empty-string $digits
     *
     * @return non-empty-string
     */
    private static function fixed(string $digits, int $exponent): string
    {
        $point = $exponent + 1;
        if ($point <= 0) {
            return '0.' . str_repeat('0', -$point) . $digits;
        }
        if ($point >= \strlen($digits)) {
            return $digits . str_repeat('0', $point - \strlen($digits));
        }

        return substr($digits, 0, $point) . '.' . substr($digits, $point);
    }
}
