<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Logging;

use Msstc4Symfony\SloBundle\Infrastructure\Logging\FailSafeLogger;
use Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class FailSafeLoggerTest extends TestCase
{
    public function testDelegatesLevelMessageAndContext(): void
    {
        $inner = new RecordingLogger();

        (new FailSafeLogger($inner))->warning('hello', ['k' => 'v']);

        self::assertSame([['warning', 'hello']], $inner->records);
        self::assertSame([['k' => 'v']], $inner->contexts);
    }

    public function testSwallowsFailuresOfTheWrappedLogger(): void
    {
        $inner = self::createStub(LoggerInterface::class);
        $inner->method('log')->willThrowException(new RuntimeException('logger broken'));
        $inner->method('warning')->willThrowException(new RuntimeException('logger broken'));

        (new FailSafeLogger($inner))->warning('hello');

        $this->expectNotToPerformAssertions();
    }
}
