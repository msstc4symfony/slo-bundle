<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RevisionTest extends TestCase
{
    public function testValid(): void
    {
        self::assertSame('0a1b2c3d', Revision::fromHex('0a1b2c3d')->value);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalid(): iterable
    {
        yield 'uppercase' => ['0A1B2C3D'];
        yield 'short' => ['0a1b2c3'];
        yield 'long' => ['0a1b2c3d4'];
        yield 'non hex' => ['0a1b2c3g'];
        yield 'trailing newline' => ["0a1b2c3d\n"];
    }

    #[DataProvider('provideInvalid')]
    public function testInvalid(string $hex): void
    {
        $this->expectException(InvalidArgumentException::class);
        Revision::fromHex($hex);
    }
}
