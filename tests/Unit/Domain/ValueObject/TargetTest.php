<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Target;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TargetTest extends TestCase
{
    /**
     * @return iterable<string, array{float, float}>
     */
    public static function provideValid(): iterable
    {
        yield '99.95' => [99.95, 0.0005];
        yield '99.9999' => [99.9999, 0.000001];
        yield '99.9' => [99.9, 0.001];
        yield '50' => [50.0, 0.5];
        yield '0.0001' => [0.0001, 0.999999];
    }

    #[DataProvider('provideValid')]
    public function testValid(float $percent, float $budget): void
    {
        $target = Target::fromPercent($percent);

        self::assertSame($percent, $target->percent);
        self::assertSame($budget, $target->errorBudgetRatio());
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function provideInvalid(): iterable
    {
        yield 'zero' => [0.0];
        yield 'hundred' => [100.0];
        yield 'negative' => [-1.0];
        yield 'above' => [100.5];
        yield 'five decimals' => [99.12345];
        yield 'nan' => [NAN];
        yield 'inf' => [INF];
    }

    #[DataProvider('provideInvalid')]
    public function testInvalid(float $percent): void
    {
        $this->expectException(InvalidArgumentException::class);
        Target::fromPercent($percent);
    }
}
