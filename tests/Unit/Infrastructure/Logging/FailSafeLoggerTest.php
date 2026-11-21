<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Logging;

use Error;
use Msstc4Symfony\SloBundle\Infrastructure\Logging\FailSafeLogger;
use Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony\RecordingLogger;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

final class FailSafeLoggerTest extends TestCase
{
    public function testDelegatesLevelMessageAndContext(): void
    {
        $inner = new RecordingLogger();

        (new FailSafeLogger($inner))->warning('hello', ['k' => 'v']);

        self::assertSame([['warning', 'hello']], $inner->records);
        self::assertSame([['k' => 'v']], $inner->contexts);
    }

    #[TestWith([new RuntimeException('logger broken')])]
    #[TestWith([new Error('logger broken')])]
    public function testSwallowsFailuresOfTheWrappedLogger(Throwable $failure): void
    {
        $inner = self::createStub(LoggerInterface::class);
        $inner->method('log')->willThrowException($failure);
        $inner->method('warning')->willThrowException($failure);

        (new FailSafeLogger($inner))->warning('hello');

        $this->expectNotToPerformAssertions();
    }
}
