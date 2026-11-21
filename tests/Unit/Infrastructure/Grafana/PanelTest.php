<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Grafana;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Infrastructure\Grafana\Panel;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PanelTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int}>
     */
    public static function invalidSizeProvider(): iterable
    {
        yield 'zero width' => [0, 4];
        yield 'negative width' => [-1, 4];
        yield 'wider than the grid' => [25, 4];
        yield 'zero height' => [4, 0];
    }

    #[DataProvider('invalidSizeProvider')]
    public function testSizeOutsideTheGridIsRejected(int $width, int $height): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(\sprintf('Panel size %dx%d', $width, $height));

        new Panel([], $width, $height);
    }

    public function testFullWidthIsAccepted(): void
    {
        self::assertSame(24, (new Panel([], 24, 1))->width);
    }
}
