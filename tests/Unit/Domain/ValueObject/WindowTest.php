<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Window;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WindowTest extends TestCase
{
    /**
     * @return iterable<string, array{string, positive-int, string}>
     */
    public static function provideValid(): iterable
    {
        yield '1h' => ['1h', 3600, '1h'];
        yield '36h' => ['36h', 129600, '36h'];
        yield '48h' => ['48h', 172800, '2d'];
        yield '30d' => ['30d', 2592000, '30d'];
        yield '90d' => ['90d', 7776000, '90d'];
        yield '2w' => ['2w', 1209600, '2w'];
        yield '12w' => ['12w', 7257600, '12w'];
        yield '1mo' => ['1mo', 2592000, '30d'];
        yield '3mo' => ['3mo', 7776000, '90d'];
        yield '7d' => ['7d', 604800, '1w'];
    }

    /**
     * @param positive-int $seconds
     */
    #[DataProvider('provideValid')]
    public function testValid(string $declared, int $seconds, string $promQl): void
    {
        $window = Window::fromString($declared);

        self::assertSame($seconds, $window->seconds());
        self::assertSame($declared, $window->toString());
        self::assertSame($promQl, $window->toPromQl());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalid(): iterable
    {
        yield '0h' => ['0h'];
        yield '91d' => ['91d'];
        yield '13w' => ['13w'];
        yield '4mo' => ['4mo'];
        yield 'bare number' => ['30'];
        yield 'minutes' => ['30m'];
        yield 'fraction' => ['1.5d'];
        yield 'year' => ['1y'];
        yield 'empty' => [''];
        yield 'leading zero' => ['01h'];
        yield 'huge' => ['99999999999999999999d'];
    }

    #[DataProvider('provideInvalid')]
    public function testInvalid(string $declared): void
    {
        $this->expectException(InvalidArgumentException::class);
        Window::fromString($declared);
    }
}
