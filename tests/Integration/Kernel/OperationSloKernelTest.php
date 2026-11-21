<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Kernel;

use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageInterface;
use Msstc4Symfony\SloBundle\Application\Port\OperationMonitorInterface;
use Override;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;

final class OperationSloKernelTest extends KernelTestCase
{
    #[Override]
    protected function setUp(): void
    {
        (new Filesystem())->remove(OperationSloTestKernel::cacheRoot());
        self::bootKernel();
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->remove(OperationSloTestKernel::cacheRoot());
    }

    public function testMeasuredOperationsBecomeSloEvents(): void
    {
        $monitor = self::getContainer()->get(OperationMonitorInterface::class);
        self::assertInstanceOf(OperationMonitorInterface::class, $monitor);

        $monitor->measure('payment.validation', static fn (): int => 1);
        try {
            $monitor->measure('payment.validation', static function (): int {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }
        $monitor->measure('undeclared.operation', static fn (): int => 1);

        $storage = self::getContainer()->get(MetricStorageInterface::class);
        self::assertInstanceOf(MetricStorageInterface::class, $storage);
        self::assertSame(
            ['payment.validation|availability|bad' => 1, 'payment.validation|availability|good' => 1],
            SloEventsExport::counts($storage->export()->body),
        );
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return OperationSloTestKernel::class;
    }
}
