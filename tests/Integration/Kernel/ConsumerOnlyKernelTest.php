<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Kernel;

use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageInterface;
use Msstc4Symfony\SloBundle\Test\Fixtures\Consumer\ArchiveInvoiceHandler;
use Msstc4Symfony\SloBundle\Test\Fixtures\Consumer\SettleInvoice;
use Msstc4Symfony\SloBundle\Test\Fixtures\Consumer\SettleInvoiceHandler;
use Override;
use PHPUnit\Framework\Attributes\RequiresMethod;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

#[RequiresMethod(MessageBusInterface::class, 'dispatch')]
final class ConsumerOnlyKernelTest extends KernelTestCase
{
    #[Override]
    protected function setUp(): void
    {
        (new Filesystem())->remove(ConsumerOnlyTestKernel::cacheRoot());
        self::bootKernel();
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->remove(ConsumerOnlyTestKernel::cacheRoot());
    }

    public function testValidateDoesNotCallAConsumedMessageSynchronous(): void
    {
        $tester = new CommandTester($this->application()->find('slo:validate'));

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringNotContainsString('WARNING', $tester->getDisplay());
    }

    public function testCoverageJudgesConsumedHandlersAndKeepsFrameworkHandlersUnsupported(): void
    {
        $tester = new CommandTester($this->application()->find('slo:coverage'));

        self::assertSame(Command::FAILURE, $tester->execute(['--format' => 'json']), 'one of two consumed handlers has no SLO');
        $statuses = [];
        $report = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($report);
        self::assertIsArray($report['points'] ?? null);
        foreach ($report['points'] as $point) {
            self::assertIsArray($point);
            if (($point['kind'] ?? null) === 'messenger_handler' && \is_string($point['point'] ?? null)) {
                $statuses[$point['point']] = $point['status'] ?? null;
            }
        }

        self::assertSame('monitored', $statuses[SettleInvoiceHandler::class . '::__invoke'] ?? null);
        self::assertSame('unmonitored', $statuses[ArchiveInvoiceHandler::class . '::__invoke'] ?? null);
        foreach ($statuses as $handler => $status) {
            if (str_starts_with($handler, 'Symfony\\')) {
                self::assertSame('unsupported', $status, $handler);
            }
        }
    }

    public function testAMessageProducedElsewhereIsOneEvent(): void
    {
        $inbox = self::getContainer()->get('messenger.transport.' . ConsumerOnlyTestKernel::INBOX);
        self::assertInstanceOf(TransportInterface::class, $inbox);
        $inbox->send(new Envelope(new SettleInvoice()));

        $consume = new CommandTester($this->application()->find('messenger:consume'));
        $consume->execute(['receivers' => [ConsumerOnlyTestKernel::INBOX], '--limit' => 1, '--time-limit' => 10, '--no-reset' => null]);

        $storage = self::getContainer()->get(MetricStorageInterface::class);
        self::assertInstanceOf(MetricStorageInterface::class, $storage);
        self::assertSame([SettleInvoiceHandler::SLO . '|availability|good' => 1], SloEventsExport::counts($storage->export()->body));
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return ConsumerOnlyTestKernel::class;
    }

    private function application(): Application
    {
        self::assertNotNull(self::$kernel);

        return new Application(self::$kernel);
    }
}
