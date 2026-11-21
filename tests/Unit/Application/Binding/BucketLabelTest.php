<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Binding;

use Msstc4Symfony\SloBundle\Application\Binding\BucketLabel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BucketLabelTest extends TestCase
{
    /**
     * Expected values are what prom/prometheus:v3.15.0 stores for a classic-histogram bucket exposed by promphp.
     *
     * @return iterable<string, array{float, non-empty-string}>
     */
    public static function provideBuckets(): iterable
    {
        yield 'integral' => [1.0, '1.0'];
        yield 'integral ten' => [10.0, '10.0'];
        yield 'integral 100000' => [100000.0, '100000.0'];
        yield 'one million switches to exponent' => [1e6, '1e+06'];
        yield 'large with fraction digits' => [1638400.0, '1.6384e+06'];
        yield 'fraction' => [0.5, '0.5'];
        yield 'quarter' => [0.25, '0.25'];
        yield 'two and a half' => [2.5, '2.5'];
        yield 'small fixed' => [0.0001, '0.0001'];
        yield 'small exponent' => [0.00001, '1e-05'];
        yield 'small exponent with digits' => [0.00001234, '1.234e-05'];
        yield 'float noise as exposed by promphp' => [0.1 + 0.2, '0.3'];
        yield 'zero' => [0.0, '0.0'];
        yield 'infinity' => [INF, '+Inf'];
    }

    #[DataProvider('provideBuckets')]
    public function testFormatsLikePrometheus3(float $bucket, string $expected): void
    {
        self::assertSame($expected, BucketLabel::fromBucket($bucket)->value);
    }

    /**
     * @return iterable<string, array{float, positive-int, bool}>
     */
    public static function provideMatches(): iterable
    {
        yield 'exact' => [0.25, 250, true];
        yield 'one second' => [1.0, 1000, true];
        yield 'one millisecond' => [0.001, 1, true];
        yield 'float noise' => [0.1 + 0.2, 300, true];
        yield 'seven milliseconds' => [0.007, 7, true];
        yield 'close but not equal' => [0.2504, 250, false];
        yield 'sub-millisecond off' => [0.2501, 250, false];
        yield 'other bucket' => [0.5, 250, false];
    }

    /**
     * @param positive-int $milliseconds
     */
    #[DataProvider('provideMatches')]
    public function testMatchesMillisecondsStrictly(float $bucket, int $milliseconds, bool $expected): void
    {
        self::assertSame($expected, BucketLabel::fromBucket($bucket)->equalsMilliseconds($milliseconds));
    }
}
