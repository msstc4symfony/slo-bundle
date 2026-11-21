<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Prometheus;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Binding\Matcher;
use Msstc4Symfony\SloBundle\Domain\ValueObject\StatusSet;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Window;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\PromQlRenderer;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class PromQlRendererTest extends TestCase
{
    public function testRendersEveryOperator(): void
    {
        self::assertSame('a="x"', PromQlRenderer::matcher(Matcher::equals('a', 'x')));
        self::assertSame('a!="x"', PromQlRenderer::matcher(Matcher::notEquals('a', 'x')));
        self::assertSame('a=~"x|y"', PromQlRenderer::matcher(Matcher::regex('a', 'x|y')));
        self::assertSame('a!~"x|y"', PromQlRenderer::matcher(Matcher::notRegex('a', 'x|y')));
        self::assertSame('{a="x",b!~"y"}', PromQlRenderer::selector([Matcher::equals('a', 'x'), Matcher::notRegex('b', 'y')]));
    }

    public function testEscapesBackslashQuoteAndNewlineForPromQlStrings(): void
    {
        self::assertSame('a="x\\\\y\\"z\\n"', PromQlRenderer::matcher(Matcher::equals('a', "x\\y\"z\n")));
    }

    public function testEscapesControlCharactersForPromQlStrings(): void
    {
        self::assertSame('a="x\\r\\ty\\x00\\x1b\\x7fz"', PromQlRenderer::matcher(Matcher::equals('a', "x\r\ty\x00\x1b\x7fz")));
        self::assertSame('a="Zürich"', PromQlRenderer::matcher(Matcher::equals('a', 'Zürich')));
    }

    public function testRejectsAValueThatIsNotUtf8(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PromQlRenderer::matcher(Matcher::equals('a', "x\xC3"));
    }

    /**
     * @param positive-int $seconds
     */
    #[TestWith([300, '5m'])]
    #[TestWith([1800, '30m'])]
    #[TestWith([3600, '1h'])]
    #[TestWith([259200, '3d'])]
    #[TestWith([1209600, '2w'])]
    #[TestWith([90, '90s'])]
    public function testDurationUsesTheLargestExactUnit(int $seconds, string $promQl): void
    {
        self::assertSame($promQl, PromQlRenderer::duration($seconds));
    }

    #[TestWith([14.4, '14.4'])]
    #[TestWith([0.001, '0.001'])]
    #[TestWith([1.0, '1'])]
    #[TestWith([0.0, '0'])]
    #[TestWith([-0.00000000001, '0'])]
    #[TestWith([0.2333333333333, '0.2333333333'])]
    public function testNumberIsTheShortestPlainDecimal(float $value, string $promQl): void
    {
        self::assertSame($promQl, PromQlRenderer::number($value));
    }

    public function testEscapeRegexQuotesRe2Metacharacters(): void
    {
        self::assertSame('a\\.b\\+c\\|d\\\\e', PromQlRenderer::escapeRegex('a.b+c|d\\e'));
        self::assertSame('app_x\\-y\\:z', PromQlRenderer::escapeRegex('app_x-y:z'));
        self::assertSame('a\\.b|c', PromQlRenderer::alternation(['a.b', 'c']));
    }

    #[TestWith(['1h', '1h'])]
    #[TestWith(['36h', '36h'])]
    #[TestWith(['48h', '2d'])]
    #[TestWith(['2w', '2w'])]
    #[TestWith(['14d', '2w'])]
    #[TestWith(['1mo', '30d'])]
    #[TestWith(['3mo', '90d'])]
    #[TestWith(['2160h', '90d'])]
    public function testWindowUsesTheLargestExactUnit(string $declared, string $promQl): void
    {
        self::assertSame($promQl, PromQlRenderer::window(Window::fromString($declared)));
    }

    /**
     * @param list<int|string> $items
     */
    #[TestWith([['5xx', 429], '429|5..'])]
    #[TestWith([['500-503'], '500|501|502|503'])]
    #[TestWith([[100, 599], '100|599'])]
    #[TestWith([[], ''])]
    public function testStatusPattern(array $items, string $pattern): void
    {
        self::assertSame($pattern, PromQlRenderer::statusPattern(StatusSet::fromList($items)));
    }
}
