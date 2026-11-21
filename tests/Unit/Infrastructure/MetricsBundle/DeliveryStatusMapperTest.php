<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\MetricsBundle;

use Msstc4Symfony\SloBundle\Domain\Enum\DeliveryStatus;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\DeliveryStatusMapper;
use Msstc4Symfony\SloBundle\Test\Integration\TestContainer;
use Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony\RecordingLogger;
use PHPUnit\Framework\TestCase;

final class DeliveryStatusMapperTest extends TestCase
{
    public function testKnownStatusesMap(): void
    {
        $logger = new RecordingLogger();
        $mapper = new DeliveryStatusMapper($logger);

        self::assertSame(DeliveryStatus::Handled, $mapper->map('handled'));
        self::assertSame(DeliveryStatus::Failed, $mapper->map('failed'));
        self::assertSame(DeliveryStatus::Retried, $mapper->map('retried'));
        self::assertSame([], $logger->records);
    }

    public function testUnknownStatusIsSkippedAndWarnedOncePerStatusUntilReset(): void
    {
        $logger = new RecordingLogger();
        $mapper = new DeliveryStatusMapper($logger);

        self::assertNull($mapper->map('skipped'));
        self::assertNull($mapper->map('skipped'));
        self::assertNull($mapper->map('rejected'));

        self::assertSame([
            ['warning', 'Unknown Messenger delivery status, its SLO events are not recorded.'],
            ['warning', 'Unknown Messenger delivery status, its SLO events are not recorded.'],
        ], $logger->records);
        self::assertSame(['skipped', 'rejected'], array_map(static fn (array $context): mixed => $context['status'] ?? null, $logger->contexts));

        $mapper->reset();
        $mapper->map('skipped');

        self::assertCount(3, $logger->records);
    }

    public function testIsResetWithTheKernel(): void
    {
        self::assertTrue(TestContainer::compile()->getDefinition(DeliveryStatusMapper::class)->hasTag('kernel.reset'));
    }
}
