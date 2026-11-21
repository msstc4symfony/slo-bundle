<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\ValueObject\LatencyThreshold;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LatencyThresholdTest extends TestCase
{
    /**
     * @return iterable<string, array{string, positive-int, string}>
     */
    public static function provideValid(): iterable
    {
        yield '200ms' => ['200ms', 200, '200ms'];
        yield '1.5s' => ['1.5s', 1500, '1500ms'];
        yield '5s' => ['5s', 5000, '5s'];
        yield '1000ms' => ['1000ms', 1000, '1s'];
        yield '0.25s' => ['0.25s', 250, '250ms'];
        yield '0.001s' => ['0.001s', 1, '1ms'];
        yield '2.500s' => ['2.500s', 2500, '2500ms'];
        yield 'smallest' => ['1ms', 1, '1ms'];
        yield 'largest ms' => ['999999999ms', 999999999, '999999999ms'];
        yield 'largest s with ms' => ['999999999.999s', 999999999999, '999999999999ms'];
    }

    /**
     * @param positive-int $ms
     */
    #[DataProvider('provideValid')]
    public function testValid(string $declared, int $ms, string $canonical): void
    {
        $threshold = LatencyThreshold::fromString($declared);

        self::assertSame($ms, $threshold->milliseconds);
        self::assertSame($ms / 1000.0, $threshold->seconds());
        self::assertSame($canonical, $threshold->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalid(): iterable
    {
        yield '0ms' => ['0ms'];
        yield '0s' => ['0s'];
        yield 'sub-millisecond' => ['1.0005s'];
        yield 'no unit' => ['200'];
        yield 'fractional ms' => ['1.5ms'];
        yield 'minutes' => ['1m'];
        yield 'empty' => [''];
        yield 'negative' => ['-5s'];
        yield 'huge' => ['99999999999999999999ms'];
        yield 'ten digits of ms' => ['1000000000ms'];
        yield 'thirteen fraction digits' => ['1.0000000000000s'];
    }

    #[DataProvider('provideInvalid')]
    public function testInvalid(string $declared): void
    {
        $this->expectException(InvalidArgumentException::class);
        LatencyThreshold::fromString($declared);
    }
}
