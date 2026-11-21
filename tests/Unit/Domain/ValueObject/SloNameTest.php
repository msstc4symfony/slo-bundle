<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SloNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function provideValid(): iterable
    {
        yield 'simple' => ['payment'];
        yield 'separators' => ['pay.ment_api-v2'];
        yield 'single char' => ['a'];
        yield 'max length' => ['a' . str_repeat('b', 62)];
    }

    #[DataProvider('provideValid')]
    public function testValid(string $name): void
    {
        self::assertSame($name, SloName::fromString($name)->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalid(): iterable
    {
        yield 'uppercase' => ['Payment'];
        yield 'digit first' => ['1abc'];
        yield 'too long' => ['a' . str_repeat('b', 63)];
        yield 'empty' => [''];
        yield 'space' => ['a b'];
        yield 'trailing newline' => ["abc\n"];
    }

    #[DataProvider('provideInvalid')]
    public function testInvalid(string $name): void
    {
        $this->expectException(InvalidArgumentException::class);
        SloName::fromString($name);
    }
}
