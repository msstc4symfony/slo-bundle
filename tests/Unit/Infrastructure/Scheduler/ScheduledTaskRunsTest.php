<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Scheduler;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Discovery\ScheduledTaskPoint;
use Msstc4Symfony\SloBundle\Application\Port\ExpectedFailureClassifierInterface;
use Msstc4Symfony\SloBundle\Application\Port\ScheduledTaskRunOutcome;
use Msstc4Symfony\SloBundle\Domain\Enum\Outcome;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Scheduler\ScheduledTaskRunListener;
use Msstc4Symfony\SloBundle\Infrastructure\Scheduler\ScheduledTaskRuns;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Operation\FakeClock;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Operation\ThrowingCatalog;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Operation\ThrowingClassifier;
use Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony\RecordingLogger;
use Override;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Console\Exception\RunCommandFailedException;
use Symfony\Component\Console\Messenger\RunCommandContext;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;
use Throwable;

#[RequiresMethod(ServiceCallMessage::class, 'getServiceId')]
final class ScheduledTaskRunsTest extends TestCase
{
    private const TASK = 'app.cleanup::__invoke';

    private const COMMAND = 'command:app:sync-partners';

    private const SECOND = 1_000_000_000;

    private CompiledSloCatalog $catalog;

    private RecordingEventRecorder $recorder;

    private RecordingRunWriter $writer;

    private FakeClock $clock;

    private RecordingLogger $logger;

    private ScheduledTaskRuns $registry;

    #[Override]
    protected function setUp(): void
    {
        $this->catalog = CatalogFixture::build(static function (CatalogAssembler $assembler): void {
            $assembler->declare(ScheduledTaskPoint::forService('app.cleanup', '__invoke'), new Slo('cleanup.run', [new Availability(99), new Latency('120s', 95)]));
            $assembler->declare(ScheduledTaskPoint::forCommand('app:sync-partners'), new Slo('partners.sync', [new Availability(99.5)], deadline: '10m'));
            $assembler->declare(ScheduledTaskPoint::forService('app.report', '__invoke'), new Slo('reports.run', [new Availability(99)], deadline: '1m'));
            $assembler->declare(ScheduledTaskPoint::forService('app.export', '__invoke'), new Slo('reports.run', [new Availability(99)], deadline: '2m'));
        }, new CatalogExtras(failureTransports: ['failed']));
        $this->recorder = new RecordingEventRecorder();
        $this->writer = new RecordingRunWriter();
        $this->clock = new FakeClock(5 * self::SECOND);
        $this->logger = new RecordingLogger();
    }

    public function testHandledRunIsOneStartOneSuccessAndOneSloEvent(): void
    {
        $listener = $this->listener();
        $envelope = new Envelope(new ServiceCallMessage('app.cleanup'));

        $this->started($envelope, 'async');
        $this->clock->nanoseconds += (int) (2.5 * self::SECOND);
        $listener->onHandled(new WorkerMessageHandledEvent($envelope->with(new DelayStamp(0)), 'async'));

        self::assertSame([['started', self::TASK], ['finished', self::TASK, ScheduledTaskRunOutcome::Success, 2.5]], $this->writer->calls);
        $this->assertRecorded(Outcome::Success, 2.5);
        self::assertSame([], $this->logger->records);
    }

    public function testFinalFailureWrappingAnExpectedFailureIsAnExpectedFailure(): void
    {
        $expected = new RuntimeException('partner offline');
        $classifier = new ExpectedFailureStub($expected);
        $envelope = new Envelope(new ServiceCallMessage('app.cleanup'));

        $this->runAndFail($this->listener(classifier: $classifier), $envelope, new HandlerFailedException($envelope, [$expected]));

        self::assertSame([$expected], $classifier->asked);
        self::assertSame(['finished', self::TASK, ScheduledTaskRunOutcome::ExpectedFailure, 1.0], $this->writer->calls[1]);
        $this->assertRecorded(Outcome::ExpectedFailure, 1.0);
    }

    public function testFinalFailureNotRecognisedByTheClassifierIsAnError(): void
    {
        $envelope = new Envelope(new ServiceCallMessage('app.cleanup'));

        $this->runAndFail($this->listener(), $envelope, new RuntimeException('boom'));

        self::assertSame(['finished', self::TASK, ScheduledTaskRunOutcome::Error, 1.0], $this->writer->calls[1]);
        $this->assertRecorded(Outcome::Error, 1.0);
    }

    public function testCommandFailureIsAlwaysAnErrorEvenWhenItWrapsAnExpectedFailure(): void
    {
        $expected = new RuntimeException('partner offline');
        $message = new RunCommandMessage("'app:sync-partners' --full");
        $envelope = new Envelope($message);
        $failure = new RunCommandFailedException($expected, new RunCommandContext($message, 1, ''));

        $this->runAndFail($this->listener(classifier: new ExpectedFailureStub($expected)), $envelope, new HandlerFailedException($envelope, [$failure]));

        self::assertSame([['started', self::COMMAND], ['finished', self::COMMAND, ScheduledTaskRunOutcome::Error, 1.0]], $this->writer->calls);
        $this->assertRecorded(Outcome::Error, 1.0);
    }

    public function testThrowingClassifierCountsAsAnErrorWithOneWarning(): void
    {
        $envelope = new Envelope(new ServiceCallMessage('app.cleanup'));

        $this->runAndFail($this->listener(classifier: new ThrowingClassifier()), $envelope, new RuntimeException('boom'));

        self::assertSame(['finished', self::TASK, ScheduledTaskRunOutcome::Error, 1.0], $this->writer->calls[1]);
        $this->assertRecorded(Outcome::Error, 1.0);
        self::assertCount(1, $this->logger->records);
        self::assertSame('warning', $this->logger->records[0][0]);
    }

    public function testRetriedAttemptFinishesAsRetriedWithoutAnSloEventAndTheNextAttemptIsFinal(): void
    {
        $listener = $this->listener();
        $envelope = new Envelope(new ServiceCallMessage('app.cleanup'));
        $retried = new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('boom'));
        $retried->setForRetry();

        $this->started($envelope, 'async');
        $this->clock->nanoseconds += self::SECOND;
        $listener->onFailed($retried);

        self::assertSame([['started', self::TASK], ['finished', self::TASK, ScheduledTaskRunOutcome::Retried, 1.0]], $this->writer->calls);
        self::assertSame([], $this->recorder->inputs);

        $this->runAndFail($listener, $envelope, new RuntimeException('boom'));

        self::assertSame([
            ['started', self::TASK],
            ['finished', self::TASK, ScheduledTaskRunOutcome::Retried, 1.0],
            ['started', self::TASK],
            ['finished', self::TASK, ScheduledTaskRunOutcome::Error, 1.0],
        ], $this->writer->calls);
        $this->assertRecorded(Outcome::Error, 1.0);
    }

    /**
     * A replay that hung would otherwise count as an unfinished run of the task; a replay is no SLO event either.
     */
    public function testReplayFromAFailureTransportIsNoRun(): void
    {
        $listener = $this->listener();
        $envelope = new Envelope(new ServiceCallMessage('app.cleanup'));

        $this->registry->start($envelope->with(new ReceivedStamp('failed'), new ConsumedByWorkerStamp()));
        // messenger:failed:retry: FailedMessageProcessingMiddleware names the original transport in the last receipt.
        $this->registry->start($envelope->with(new SentToFailureTransportStamp('async'), new ReceivedStamp('async'), new ConsumedByWorkerStamp()));

        $listener->onHandled(new WorkerMessageHandledEvent($envelope, 'failed'));

        $this->assertNothingWritten();
    }

    public function testRunFinishingLongAfterItsDeadlineStillReachesTheRecorderWhichSkipsItPerSlo(): void
    {
        $listener = $this->listener();
        $envelope = new Envelope(new RunCommandMessage('app:sync-partners'));

        $this->started($envelope, 'async');
        $this->clock->nanoseconds += 3600 * self::SECOND;
        $listener->onFailed(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('boom')));

        self::assertSame([['started', self::COMMAND], ['finished', self::COMMAND, ScheduledTaskRunOutcome::Error, 3600.0]], $this->writer->calls);
        $this->assertRecorded(Outcome::Error, 3600.0);
    }

    public function testTaskWithOnlyAConflictedSloNameIsMonitored(): void
    {
        $listener = $this->listener();
        $envelope = new Envelope(new ServiceCallMessage('app.report'));

        $this->started($envelope, 'async');
        $this->clock->nanoseconds += 300 * self::SECOND;
        $listener->onHandled(new WorkerMessageHandledEvent($envelope, 'async'));

        self::assertSame([['started', 'app.report::__invoke'], ['finished', 'app.report::__invoke', ScheduledTaskRunOutcome::Success, 300.0]], $this->writer->calls);
        self::assertCount(1, $this->recorder->inputs);
    }

    public function testUnmonitoredTaskAndOtherMessagesWriteNothing(): void
    {
        $listener = $this->listener();
        foreach ([new ServiceCallMessage('app.unmonitored'), new stdClass(), new RunCommandMessage('app:other')] as $message) {
            $envelope = new Envelope($message);
            $this->started($envelope, 'async');
            $listener->onHandled(new WorkerMessageHandledEvent($envelope, 'async'));
        }

        $this->assertNothingWritten();
    }

    public function testOutcomeWithoutARecordedStartWritesNothing(): void
    {
        $listener = $this->listener();
        $envelope = new Envelope(new ServiceCallMessage('app.cleanup'));
        $this->started($envelope, 'async');
        $this->writer->calls = [];

        $this->registry->reset();
        $listener->onHandled(new WorkerMessageHandledEvent($envelope, 'async'));
        $listener->onFailed(new WorkerMessageFailedEvent($envelope, 'async', new RuntimeException('boom')));

        $this->assertNothingWritten();
    }

    public function testFailingStartWriteIsLoggedAndTheRunIsStillRecorded(): void
    {
        $this->writer = new RecordingRunWriter(failOnStarted: true);
        $listener = $this->listener();
        $envelope = new Envelope(new ServiceCallMessage('app.cleanup'));

        $this->started($envelope, 'async');
        $listener->onHandled(new WorkerMessageHandledEvent($envelope, 'async'));

        self::assertSame([['warning', 'Scheduled task run was not recorded.']], $this->logger->records);
        self::assertSame(['task' => self::TASK], array_diff_key($this->logger->contexts[0], ['exception' => true]));
        self::assertSame([['finished', self::TASK, ScheduledTaskRunOutcome::Success, 0.0]], $this->writer->calls);
        $this->assertRecorded(Outcome::Success, 0.0);
    }

    public function testFailingFinishWriteIsLoggedWithTheTaskAndTheSloEventIsRecorded(): void
    {
        $this->writer = new RecordingRunWriter(failOnFinished: true);
        $listener = $this->listener();
        $envelope = new Envelope(new ServiceCallMessage('app.cleanup'));

        $this->started($envelope, 'async');
        $listener->onHandled(new WorkerMessageHandledEvent($envelope, 'async'));

        self::assertSame([['warning', 'Scheduled task run was not recorded.']], $this->logger->records);
        self::assertSame(['task' => self::TASK], array_diff_key($this->logger->contexts[0], ['exception' => true]));
        $this->assertRecorded(Outcome::Success, 0.0);
    }

    public function testFailingCatalogIsLoggedAndNothingLeavesTheListener(): void
    {
        $listener = $this->listener(catalog: new ThrowingCatalog());
        $envelope = new Envelope(new ServiceCallMessage('app.cleanup'));

        $this->registry->start($envelope->with(new ReceivedStamp('async')));
        $listener->onHandled(new WorkerMessageHandledEvent($envelope, 'async'));

        $this->assertNothingWritten();
        self::assertSame([['warning', 'Scheduled task run was not recorded.']], $this->logger->records);
    }

    private function listener(?SloCatalogInterface $catalog = null, ?ExpectedFailureClassifierInterface $classifier = null): ScheduledTaskRunListener
    {
        $this->registry = new ScheduledTaskRuns(
            $catalog ?? $this->catalog,
            $this->catalog,
            $this->recorder,
            $this->writer,
            $classifier ?? new ExpectedFailureStub(),
            $this->clock,
            $this->logger,
        );

        return new ScheduledTaskRunListener($this->registry);
    }

    /**
     * What ScheduledTaskRunMiddleware does with an envelope a worker consumed.
     */
    private function started(Envelope $envelope, string $transport): void
    {
        $this->registry->start($envelope->with(new ReceivedStamp($transport), new ConsumedByWorkerStamp()));
    }

    private function runAndFail(ScheduledTaskRunListener $listener, Envelope $envelope, Throwable $error): void
    {
        $this->started($envelope, 'async');
        $this->clock->nanoseconds += self::SECOND;
        $listener->onFailed(new WorkerMessageFailedEvent($envelope, 'async', $error));
    }

    private function assertRecorded(Outcome $outcome, float $duration): void
    {
        self::assertCount(1, $this->recorder->inputs);
        $input = $this->recorder->inputs[0];
        self::assertSame(PointType::ScheduledTask, $input->point->type);
        self::assertSame($outcome, $input->observation->outcome);
        self::assertSame($duration, $input->observation->durationSeconds);
        self::assertFalse($input->observation->onFailureTransport);
    }

    private function assertNothingWritten(): void
    {
        self::assertSame([], $this->writer->calls);
        self::assertSame([], $this->recorder->inputs);
    }
}
