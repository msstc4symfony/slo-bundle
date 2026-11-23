<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Generation;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Generation\AlertmanagerReceivers;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class AlertmanagerReceiversTest extends TestCase
{
    public function testNamesListPageThenTicket(): void
    {
        self::assertSame(['p', 't'], (new AlertmanagerReceivers('p', 't'))->names());
    }

    public function testSameNameIsListedOnce(): void
    {
        self::assertSame(['a'], (new AlertmanagerReceivers('a', 'a'))->names());
    }

    /**
     * @param non-empty-string $page
     * @param non-empty-string $ticket
     */
    #[TestWith(['a b', 't'])]
    #[TestWith(['p', 'x/y'])]
    public function testInvalidNameIsRejected(string $page, string $ticket): void
    {
        $this->expectException(InvalidArgumentException::class);
        new AlertmanagerReceivers($page, $ticket);
    }
}
