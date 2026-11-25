<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Deadline;
use Msstc4Symfony\SloBundle\Domain\ValueObject\LatencyThreshold;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeadlineTest extends TestCase
{
    /**
     * @return iterable<string, array{string, positive-int, string}>
     */
    public static function provideValid(): iterable
    {
        yield '30m' => ['30m', 1_800_000, '30m'];
        yield '1.5s' => ['1.5s', 1500, '1500ms'];
        yield '90s' => ['90s', 90_000, '90s'];
        yield '120000ms' => ['120000ms', 120_000, '2m'];
        yield '2h' => ['2h', 7_200_000, '2h'];
        yield '60m' => ['60m', 3_600_000, '1h'];
        yield '0.001s' => ['0.001s', 1, '1ms'];
        yield '2.500s' => ['2.500s', 2500, '2500ms'];
        yield 'largest ms' => ['999999999ms', 999_999_999, '999999999ms'];
    }

    /**
     * @param positive-int $ms
     */
    #[DataProvider('provideValid')]
    public function testValid(string $declared, int $ms, string $canonical): void
    {
        $deadline = Deadline::fromString($declared);

        self::assertSame($ms, $deadline->milliseconds);
        self::assertSame($ms / 1000.0, $deadline->seconds());
        self::assertSame($canonical, $deadline->toString());
        self::assertSame($ms, Deadline::fromString($deadline->toString())->milliseconds);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalid(): iterable
    {
        yield '0s' => ['0s'];
        yield '0m' => ['0m'];
        yield 'empty' => [''];
        yield 'days' => ['1d'];
        yield 'finer than a millisecond' => ['1.0001s'];
        yield 'too long' => ['1000000000ms'];
        yield 'too many hours' => ['999999h'];
        yield 'negative' => ['-1m'];
        yield 'decimal minutes' => ['1.5m'];
        yield 'no unit' => ['30'];
        yield 'trailing newline' => ["30m\n"];
    }

    #[DataProvider('provideInvalid')]
    public function testInvalid(string $declared): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^Invalid deadline /');
        Deadline::fromString($declared);
    }

    public function testFromThreshold(): void
    {
        self::assertSame(200, Deadline::fromThreshold(LatencyThreshold::fromString('200ms'))->milliseconds);
    }

    public function testFromMilliseconds(): void
    {
        self::assertSame('2m', Deadline::fromMilliseconds(120_000)->toString());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function provideInvalidMilliseconds(): iterable
    {
        yield 'zero' => [0];
        yield 'above the maximum' => [1_000_000_000];
    }

    #[DataProvider('provideInvalidMilliseconds')]
    public function testFromMillisecondsRejectsOutOfRange(int $milliseconds): void
    {
        $this->expectException(InvalidArgumentException::class);
        Deadline::fromMilliseconds($milliseconds);
    }
}
