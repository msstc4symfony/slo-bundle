<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Binding;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Binding\Matcher;
use PHPUnit\Framework\TestCase;

final class MatcherTest extends TestCase
{
    public function testRendersEveryOperator(): void
    {
        self::assertSame('a="x"', Matcher::equals('a', 'x')->toPromQl());
        self::assertSame('a!="x"', Matcher::notEquals('a', 'x')->toPromQl());
        self::assertSame('a=~"x|y"', Matcher::regex('a', 'x|y')->toPromQl());
        self::assertSame('a!~"x|y"', Matcher::notRegex('a', 'x|y')->toPromQl());
    }

    public function testEscapesBackslashQuoteAndNewlineForPromQlStrings(): void
    {
        self::assertSame('a="x\\\\y\\"z\\n"', Matcher::equals('a', "x\\y\"z\n")->toPromQl());
    }

    public function testEscapeRegexQuotesRe2Metacharacters(): void
    {
        self::assertSame('a\\.b\\+c\\|d\\\\e', Matcher::escapeRegex('a.b+c|d\\e'));
        self::assertSame('app_x\\-y\\:z', Matcher::escapeRegex('app_x-y:z'));
    }

    public function testRejectsEmptyLabelAndInvalidLabelName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Matcher::equals('9bad', 'x');
    }
}
