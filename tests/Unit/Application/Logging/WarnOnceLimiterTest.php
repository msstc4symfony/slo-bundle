<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Logging;

use Msstc4Symfony\SloBundle\Application\Logging\WarnOnceLimiter;
use Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class WarnOnceLimiterTest extends TestCase
{
    public function testEachKeyIsLoggedOnceWithItsContext(): void
    {
        $logger = new RecordingLogger();
        $limiter = new WarnOnceLimiter($logger, 'suppressed');

        $limiter->warn('a', 'first', ['slo' => 'a']);
        $limiter->warn('a', 'first again', ['slo' => 'a']);
        $limiter->warn('b', 'second');

        self::assertSame([['warning', 'first'], ['warning', 'second']], $logger->records);
        self::assertSame([['slo' => 'a'], []], $logger->contexts);
    }

    public function testKeysBeyondTheCapacityLogOneSuppressionNotice(): void
    {
        $logger = new RecordingLogger();
        $limiter = new WarnOnceLimiter($logger, 'suppressed', 2);

        foreach (['a', 'b', 'c', 'd', 'a'] as $key) {
            $limiter->warn($key, 'message ' . $key);
        }

        self::assertSame([['warning', 'message a'], ['warning', 'message b'], ['warning', 'suppressed']], $logger->records);
    }

    public function testResetForgetsKeysAndTheSuppression(): void
    {
        $logger = new RecordingLogger();
        $limiter = new WarnOnceLimiter($logger, 'suppressed', 1);
        $limiter->warn('a', 'message a');
        $limiter->warn('b', 'message b');

        $limiter->reset();
        $limiter->warn('a', 'message a');
        $limiter->warn('b', 'message b');

        self::assertSame(['message a', 'suppressed', 'message a', 'suppressed'], array_column($logger->records, 1));
    }
}
