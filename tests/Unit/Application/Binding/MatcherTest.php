<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Binding;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Binding\Matcher;
use PHPUnit\Framework\TestCase;

final class MatcherTest extends TestCase
{
    public function testRejectsEmptyLabelAndInvalidLabelName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Matcher::equals('9bad', 'x');
    }
}
