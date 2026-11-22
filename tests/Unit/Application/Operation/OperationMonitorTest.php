<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Operation;

use LogicException;
use Msstc4Symfony\LogicBundle\Application\Action\ActionOutcome;
use Msstc4Symfony\LogicBundle\Application\Action\ExpectedFailureInterface;
use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Binding\RevisionResolver;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Action;
use Msstc4Symfony\SloBundle\Application\Operation\NoopOperationHandle;
use Msstc4Symfony\SloBundle\Application\Operation\OperationMonitor;
use Msstc4Symfony\SloBundle\Application\Port\EventWriterInterface;
use Msstc4Symfony\SloBundle\Application\Port\OperationHandleInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Msstc4Symfony\SloBundle\Domain\Service\EventClassifier;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\LogicBundle\ActionOutcomeClassifier;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsBundleSeriesCatalog;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\InMemoryRouteCatalog;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Evaluation\RecordEvent\FixedPrecomputedRevisions;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Evaluation\RecordEvent\InMemoryEventWriter;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Evaluation\RecordEvent\ThrowingEventWriter;
use Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony\RecordingLogger;
use Override;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Exception\ValidationFailedException;

final class OperationMonitorTest extends TestCase
{
    private FakeClock $clock;

    private InMemoryEventWriter $writer;

    private RecordingLogger $logger;

    private OperationMonitor $monitor;

    #[Override]
    protected function setUp(): void
    {
        $this->clock = new FakeClock();
        $this->writer = new InMemoryEventWriter();
        $this->logger = new RecordingLogger();
        $this->monitor = $this->monitor($this->writer);
    }

    public function testMeasureReturnsTheResultAndRecordsSuccess(): void
    {
        $result = $this->monitor->measure('payment.validation', function (): int {
            $this->clock->nanoseconds += 30_000_000;

            return 42;
        });

        self::assertSame(42, $result);
        self::assertSame(['good', 'good'], array_column($this->writer->writes, 3));
        self::assertSame(['availability', 'latency'], array_column($this->writer->writes, 1));
    }

    public function testMeasureUsesTheElapsedTimeForLatency(): void
    {
        $this->monitor->measure('payment.validation', function (): void {
            $this->clock->nanoseconds += 80_000_000;
        });

        self::assertSame(['good', 'bad'], array_column($this->writer->writes, 3));
    }

    public function testMeasureRethrowsTheSameInstanceAndRecordsError(): void
    {
        $failure = new RuntimeException('boom');

        $caught = null;
        try {
            $this->monitor->measure('payment.validation', static function () use ($failure): int {
                throw $failure;
            });
        } catch (RuntimeException $e) {
            $caught = $e;
        }

        self::assertSame($failure, $caught);

        self::assertSame([['payment.validation', 'availability', 'bad']], $this->availabilityWrites());
    }

    public function testExpectedFailureIsGoodForAvailability(): void
    {
        if (!interface_exists(ExpectedFailureInterface::class)) {
            self::markTestSkipped('msstc4symfony/logic-bundle is not installed');
        }

        $failure = new ExpectedFailure('refused');

        $caught = null;
        try {
            $this->monitor->measure('payment.validation', static function () use ($failure): int {
                throw $failure;
            });
        } catch (ExpectedFailure $e) {
            $caught = $e;
        }

        self::assertSame($failure, $caught);

        self::assertSame([['payment.validation', 'availability', 'good']], $this->availabilityWrites());
    }

    #[RequiresMethod(ActionOutcome::class, 'of')]
    public function testValidationFailureIsClassifiedAsLogicBundleDoes(): void
    {
        $failure = new ValidationFailedException('payment', new ConstraintViolationList());

        try {
            $this->monitor->measure('payment.validation', static function () use ($failure): int {
                throw $failure;
            });
        } catch (ValidationFailedException $e) {
            self::assertSame($failure, $e);
        }

        self::assertSame([['payment.validation', 'availability', 'good']], $this->availabilityWrites());
    }

    public function testStartedHandleFailThenEndTwiceRecordsOnce(): void
    {
        $handle = $this->monitor->start('payment.validation');
        $handle->fail();
        $handle->end();
        $handle->end();

        self::assertCount(1, $this->availabilityWrites());
        self::assertSame('bad', $this->availabilityWrites()[0][2]);
    }

    public function testFirstOutcomeWins(): void
    {
        $handle = $this->monitor->start('payment.validation');
        $handle->failExpected();
        $handle->fail();
        $handle->succeed();
        $handle->end();

        self::assertSame('good', $this->availabilityWrites()[0][2]);
    }

    public function testEndWithoutOutcomeIsSuccess(): void
    {
        $handle = $this->monitor->start('payment.validation');
        $handle->end();

        self::assertSame('good', $this->availabilityWrites()[0][2]);
    }

    public function testOutcomeAfterEndIsIgnored(): void
    {
        $handle = $this->monitor->start('payment.validation');
        $handle->end();
        $handle->fail();
        $handle->end();

        self::assertCount(1, $this->availabilityWrites());
        self::assertSame('good', $this->availabilityWrites()[0][2]);
    }

    public function testHandleWithoutEndRecordsNothing(): void
    {
        $handle = $this->monitor->start('payment.validation');
        $handle->fail();
        unset($handle);

        self::assertSame([], $this->writer->writes);
    }

    public function testUndeclaredNameWarnsOncePerNameAndNeverWrites(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->monitor->start('unknown.op')->end();
            self::assertSame(7, $this->monitor->measure('unknown.op', static fn (): int => 7));
        }
        $this->monitor->start('other.op')->end();

        self::assertSame([], $this->writer->writes);
        self::assertCount(2, $this->logger->records);
        self::assertSame('warning', $this->logger->records[0][0]);
        self::assertSame('unknown.op', $this->logger->contexts[0]['operation']);
        self::assertSame('other.op', $this->logger->contexts[1]['operation']);
    }

    public function testWarnedNamesAreBoundedAndResetRestoresWarnings(): void
    {
        for ($i = 0; $i < 150; $i++) {
            $this->monitor->start('unknown.op' . $i)->end();
        }

        self::assertCount(101, $this->logger->records);
        self::assertSame('Further undeclared SLO operation names are suppressed until reset.', $this->logger->records[100][1]);
        $this->monitor->start('unknown.op0')->end();
        $this->monitor->start('unknown.op149')->end();
        self::assertCount(101, $this->logger->records);

        $this->monitor->reset();
        $this->monitor->start('unknown.op0')->end();

        self::assertCount(102, $this->logger->records);
    }

    public function testInvalidNamesShareOneWarningKey(): void
    {
        $this->monitor->start('Bad 1')->end();
        $this->monitor->start('Bad 2')->end();

        self::assertCount(1, $this->logger->records);
    }

    public function testResetAllowsTheWarningAgain(): void
    {
        $this->monitor->start('unknown.op')->end();
        $this->monitor->reset();
        $this->monitor->start('unknown.op')->end();

        self::assertCount(2, $this->logger->records);
    }

    public function testUndeclaredNameStillReturnsAUsableHandle(): void
    {
        $handle = $this->monitor->start('unknown.op');

        self::assertInstanceOf(OperationHandleInterface::class, $handle);
        $handle->failExpected(new RuntimeException('x'));
        $handle->fail();
        $handle->succeed();
        $handle->end();

        self::assertSame([], $this->writer->writes);
    }

    public function testInvalidNameIsTreatedAsUndeclared(): void
    {
        $this->monitor->start('')->end();
        $this->monitor->start('Bad Name')->end();
        self::assertSame(1, $this->monitor->measure('', static fn (): int => 1));

        self::assertSame([], $this->writer->writes);
        self::assertCount(1, $this->logger->records);
    }

    public function testWriterFailureInsideEndDoesNotThrow(): void
    {
        $monitor = $this->monitor(new ThrowingEventWriter());

        $handle = $monitor->start('payment.validation');
        $handle->end();

        self::assertSame(5, $monitor->measure('payment.validation', static fn (): int => 5));
        self::assertNotSame([], $this->logger->records);
    }

    public function testCatalogFailureDoesNotThrow(): void
    {
        $monitor = new OperationMonitor(new ThrowingCatalog(), $this->action($this->writer, $this->catalog()), $this->clock, new ActionOutcomeClassifier(), $this->logger);

        $monitor->start('payment.validation')->end();

        self::assertSame(3, $monitor->measure('payment.validation', static fn (): int => 3));
        self::assertSame([], $this->writer->writes);
    }

    public function testFailingClockAtStartYieldsANoopHandleAndAWarning(): void
    {
        $catalog = $this->catalog();
        $monitor = new OperationMonitor($catalog, $this->action($this->writer, $catalog), new ThrowingClock(), new ActionOutcomeClassifier(), $this->logger);

        $handle = $monitor->start('payment.validation');
        $handle->end();

        self::assertInstanceOf(NoopOperationHandle::class, $handle);
        self::assertSame([], $this->writer->writes);
        self::assertSame([['warning', 'SLO operation cannot be monitored, nothing is recorded.']], $this->logger->records);
    }

    public function testFailingClockAtEndIsLoggedNotThrown(): void
    {
        $catalog = $this->catalog();
        $monitor = new OperationMonitor($catalog, $this->action($this->writer, $catalog), new ThrowingClock(1), new ActionOutcomeClassifier(), $this->logger);

        self::assertSame(7, $monitor->measure('payment.validation', static fn (): int => 7));
        self::assertSame([], $this->writer->writes);
        self::assertSame([['warning', 'SLO operation event was not recorded.']], $this->logger->records);
        self::assertSame('payment.validation', $this->logger->contexts[0]['operation']);
    }

    public function testFailingClassificationKeepsTheUserExceptionAndCountsAnError(): void
    {
        $catalog = $this->catalog();
        $monitor = new OperationMonitor($catalog, $this->action($this->writer, $catalog), $this->clock, new ThrowingClassifier(), $this->logger);
        $thrown = new RuntimeException('declined');

        $caught = null;
        try {
            $monitor->measure('payment.validation', static function () use ($thrown): never {
                throw $thrown;
            });
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        self::assertSame($thrown, $caught);

        self::assertSame([['payment.validation', 'availability', 'bad']], $this->availabilityWrites());
        self::assertSame('SLO operation exception could not be classified, it counts as an error.', $this->logger->records[0][1]);
        self::assertInstanceOf(LogicException::class, $this->logger->contexts[0]['exception']);
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function availabilityWrites(): array
    {
        $rows = [];
        foreach ($this->writer->writes as $write) {
            if ($write[1] === 'availability') {
                $rows[] = [$write[0], $write[1], $write[3]];
            }
        }

        return $rows;
    }

    private function catalog(): CompiledSloCatalog
    {
        return CatalogFixture::build(static function (CatalogAssembler $assembler): void {
            $assembler->declare(PointId::of(PointType::Operation, 'payment.validation'), new Slo(
                'payment.validation',
                [new Availability(99.9), new Latency('50ms', 99)],
                strategy: StrategyPreference::Dedicated,
            ));
        });
    }

    private function action(EventWriterInterface $writer, CompiledSloCatalog $catalog): Action
    {
        return new Action($catalog, new MetricBindingResolver(new InMemoryRouteCatalog([]), $catalog, new MetricsBundleSeriesCatalog()), new RevisionResolver(new RevisionCalculator(), $catalog), new EventClassifier(), $writer, $this->logger, new FixedPrecomputedRevisions());
    }

    private function monitor(EventWriterInterface $writer): OperationMonitor
    {
        $catalog = $this->catalog();

        return new OperationMonitor($catalog, $this->action($writer, $catalog), $this->clock, new ActionOutcomeClassifier(), $this->logger);
    }
}
