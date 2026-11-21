<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\MetricsBundle;

use Msstc4Symfony\MetricsBundle\Application\Metric\MessengerMessageStatus;
use Msstc4Symfony\MetricsBundle\Application\Port\MessengerMeasurement;
use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MessengerSloObserver;
use Msstc4Symfony\SloBundle\Test\Fixtures\Message\ProcessPayment;
use Msstc4Symfony\SloBundle\Test\Fixtures\Message\RefundPayment;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\InMemoryRouteCatalog;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Evaluation\RecordEvent\InMemoryEventWriter;
use Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony\RecordingLogger;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MessengerSloObserverTest extends TestCase
{
    private CompiledSloCatalog $catalog;

    private InMemoryEventWriter $writer;

    private RecordingLogger $logger;

    private MessengerSloObserver $observer;

    #[Override]
    protected function setUp(): void
    {
        $this->catalog = CatalogFixture::build(static function (CatalogAssembler $assembler): void {
            $assembler->declare(PointId::of(PointType::MessengerMessage, ProcessPayment::class), new Slo('payment.process', [new Availability(99.9), new Latency('1s', 99)], strategy: 'dedicated'));
        }, new CatalogExtras(failureTransports: ['dead', 'failed']));
        $catalog = $this->catalog;
        $this->writer = new InMemoryEventWriter();
        $this->logger = new RecordingLogger();
        $this->observer = new MessengerSloObserver(
            $catalog,
            $catalog,
            ObserverFixture::action($catalog, new InMemoryRouteCatalog(), $this->writer, $this->logger),
            $this->logger,
        );
    }

    /**
     * @return iterable<string, array{non-empty-string, MessengerMessageStatus, float|null, list<string>}>
     */
    public static function outcomeProvider(): iterable
    {
        yield 'handled fast' => ['async', MessengerMessageStatus::HANDLED, 0.1, ['availability:good', 'latency:good']];
        yield 'handled slow' => ['async', MessengerMessageStatus::HANDLED, 1.5, ['availability:good', 'latency:bad']];
        yield 'handled without start' => ['async', MessengerMessageStatus::HANDLED, null, ['availability:good']];
        yield 'failed' => ['async', MessengerMessageStatus::FAILED, 0.1, ['availability:bad']];
        yield 'retried is not final' => ['async', MessengerMessageStatus::RETRIED, 0.1, []];
        yield 'replay from the failure transport' => ['failed', MessengerMessageStatus::HANDLED, 0.1, []];
        yield 'failure on a second failure transport' => ['dead', MessengerMessageStatus::FAILED, 0.1, []];
    }

    /**
     * @param non-empty-string $transport
     * @param list<string> $expected
     */
    #[DataProvider('outcomeProvider')]
    public function testClassifiesTheOutcome(string $transport, MessengerMessageStatus $status, ?float $duration, array $expected): void
    {
        $this->observer->onMessageMeasured(new MessengerMeasurement($transport, ProcessPayment::class, $status, $duration, null));

        self::assertSame($expected, ObserverFixture::results($this->writer));
        self::assertSame([], $this->logger->records);
    }

    public function testMessageWithoutSloRecordsNothing(): void
    {
        $this->observer->onMessageMeasured(new MessengerMeasurement('async', RefundPayment::class, MessengerMessageStatus::FAILED, 0.1, null));

        self::assertSame([], $this->writer->writes);
    }

    public function testInvalidMeasurementIsLoggedNotThrown(): void
    {
        $this->observer->onMessageMeasured(new MessengerMeasurement('async', ProcessPayment::class, MessengerMessageStatus::HANDLED, INF, null));

        self::assertSame([], $this->writer->writes);
        self::assertCount(1, $this->logger->records);
        self::assertSame(ProcessPayment::class, $this->logger->contexts[0]['message']);
    }
}
