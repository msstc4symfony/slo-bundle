<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Kernel;

use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageInterface;
use Msstc4Symfony\SloBundle\Application\Port\OperationMonitorInterface;
use Msstc4Symfony\SloBundle\Test\Fixtures\Runtime\ChargeCard;
use Msstc4Symfony\SloBundle\Test\Fixtures\Runtime\ChargeCardHandler;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;

final class NoRouterKernelTest extends KernelTestCase
{
    #[Override]
    protected function setUp(): void
    {
        (new Filesystem())->remove(NoRouterTestKernel::cacheRoot());
        self::bootKernel();
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->remove(NoRouterTestKernel::cacheRoot());
    }

    public function testOperationSloIsRecordedWithoutARouter(): void
    {
        self::assertFalse(self::getContainer()->has('router'));
        $monitor = self::getContainer()->get(OperationMonitorInterface::class);
        self::assertInstanceOf(OperationMonitorInterface::class, $monitor);

        $monitor->measure('payment.validation', static fn (): int => 1);

        self::assertSame(['payment.validation|availability|good' => 1], $this->counts());
    }

    #[RequiresMethod(MessageBusInterface::class, 'dispatch')]
    public function testMessengerSloIsRecordedWithoutARouter(): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch(new ChargeCard('c-1'));

        $consume = new CommandTester($this->application()->find('messenger:consume'));
        $consume->execute(['receivers' => [NoRouterTestKernel::ASYNC], '--limit' => 1, '--time-limit' => 10, '--no-reset' => null]);

        self::assertSame([ChargeCardHandler::SLO . '|availability|good' => 1], $this->counts());
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function commandProvider(): iterable
    {
        yield 'list' => ['slo:list'];
        yield 'validate' => ['slo:validate'];
        yield 'coverage' => ['slo:coverage'];
    }

    /**
     * @param non-empty-string $name
     */
    #[DataProvider('commandProvider')]
    public function testCommandsTreatAMissingRouterAsNoRoutes(string $name): void
    {
        $tester = new CommandTester($this->application()->find($name));

        self::assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return NoRouterTestKernel::class;
    }

    private function application(): Application
    {
        self::assertNotNull(self::$kernel);

        return new Application(self::$kernel);
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $storage = self::getContainer()->get(MetricStorageInterface::class);
        self::assertInstanceOf(MetricStorageInterface::class, $storage);

        return SloEventsExport::counts($storage->export()->body);
    }
}
