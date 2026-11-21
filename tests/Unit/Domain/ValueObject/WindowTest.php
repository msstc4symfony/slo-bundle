<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Window;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\PromQlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
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
        yield 'upper bound in hours' => ['2160h', 7776000, '90d'];
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
        self::assertSame($promQl, PromQlRenderer::window($window));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalid(): iterable
    {
        yield '0h' => ['0h'];
        yield '91d' => ['91d'];
        yield 'one hour above 90d' => ['2161h'];
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

    /**
     * @param non-empty-string $declared
     * @param non-empty-string $canonical
     */
    #[TestWith(['30d', '1mo'])]
    #[TestWith(['1mo', '1mo'])]
    #[TestWith(['14d', '2w'])]
    #[TestWith(['48h', '2d'])]
    #[TestWith(['36h', '36h'])]
    #[TestWith(['90d', '3mo'])]
    public function testCanonicalUsesTheLargestExactUnit(string $declared, string $canonical): void
    {
        $window = Window::fromString($declared)->canonical();

        self::assertSame($canonical, $window->toString());
        self::assertSame(Window::fromString($declared)->seconds(), $window->seconds());
    }
}
