<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Generation;

use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use PHPUnit\Framework\TestCase;

final class SelectorTest extends TestCase
{
    public function testComponentsAreSortedAndDeduplicated(): void
    {
        self::assertSame(['a', 'b', 'c'], (new Selector('shop', ['c', 'a', 'b', 'a']))->components);
    }
}
