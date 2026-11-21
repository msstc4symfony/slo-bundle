<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Kernel;

use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageInterface;
use Msstc4Symfony\SloBundle\Test\Fixtures\Runtime\ChargeCard;
use Msstc4Symfony\SloBundle\Test\Fixtures\Runtime\ChargeCardHandler;
use Msstc4Symfony\SloBundle\Test\Fixtures\Runtime\WriteAudit;
use Override;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class MessengerSloKernelTest extends KernelTestCase
{
    private const GOOD = ChargeCardHandler::SLO . '|availability|good';

    private const BAD = ChargeCardHandler::SLO . '|availability|bad';

    #[Override]
    protected function setUp(): void
    {
        (new Filesystem())->remove(MessengerSloTestKernel::cacheRoot());
        self::bootKernel();
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->remove(MessengerSloTestKernel::cacheRoot());
    }

    public function testHandledMessageIsOneGoodEvent(): void
    {
        $this->dispatch(new ChargeCard('c-1'));
        $this->consume(MessengerSloTestKernel::ASYNC, 1);

        self::assertSame([self::GOOD => 1], $this->counts());
    }

    public function testRetriesThenHandledIsOneGoodEventOnly(): void
    {
        $this->dispatch(new ChargeCard('c-2', failures: 2));
        $this->consume(MessengerSloTestKernel::ASYNC, 3);

        self::assertSame([self::GOOD => 1], $this->counts());
    }

    public function testPermanentFailureIsOneBadEventAndTheReplayAddsNothing(): void
    {
        $this->dispatch(new ChargeCard('c-3', failures: 3));
        $this->consume(MessengerSloTestKernel::ASYNC, 3);

        self::assertSame([self::BAD => 1], $this->counts());
        self::assertCount(1, $this->failureTransport()->get());

        $retry = new CommandTester($this->application()->find('messenger:failed:retry'));
        $retry->execute(['--force' => true]);

        $retry->assertCommandIsSuccessful();
        self::assertSame([], $this->failureTransport()->get());
        self::assertStringContainsString(
            'symfony_messenger_message_handled{application="test",component="test",transport="failed",message="ChargeCard",status="handled"} 1',
            $this->export(),
        );
        self::assertSame([self::BAD => 1], $this->counts());
    }

    public function testSynchronouslyHandledMessageIsNotMeasured(): void
    {
        $this->dispatch(new WriteAudit());

        self::assertSame([], $this->counts());
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return MessengerSloTestKernel::class;
    }

    private function dispatch(object $message): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch($message);
    }

    /**
     * @param positive-int $limit
     */
    private function consume(string $transport, int $limit): void
    {
        // Without --no-reset the worker resets the in-memory transport after each message and drops the retry.
        $consume = new CommandTester($this->application()->find('messenger:consume'));
        $consume->execute(['receivers' => [$transport], '--limit' => $limit, '--time-limit' => 10, '--no-reset' => null]);
    }

    private function failureTransport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.' . MessengerSloTestKernel::FAILED);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
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
        return SloEventsExport::counts($this->export());
    }

    private function export(): string
    {
        $storage = self::getContainer()->get(MetricStorageInterface::class);
        self::assertInstanceOf(MetricStorageInterface::class, $storage);

        return $storage->export()->body;
    }
}
