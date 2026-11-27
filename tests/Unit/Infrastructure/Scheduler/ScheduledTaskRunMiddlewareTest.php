<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Scheduler;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Discovery\ScheduledTaskPoint;
use Msstc4Symfony\SloBundle\Application\Port\ScheduledTaskRunOutcome;
use Msstc4Symfony\SloBundle\Domain\Enum\Outcome;
use Msstc4Symfony\SloBundle\Infrastructure\Scheduler\ScheduledTaskOutcomeMiddleware;
use Msstc4Symfony\SloBundle\Infrastructure\Scheduler\ScheduledTaskRunMiddleware;
use Msstc4Symfony\SloBundle\Infrastructure\Scheduler\ScheduledTaskRuns;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Operation\FakeClock;
use Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony\RecordingLogger;
use Override;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\DelayedMessageHandlingException;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\DispatchAfterCurrentBusMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;
use Throwable;

/**
 * The two middlewares as the bus runs them: outcome first, user middleware, run start, the handlers.
 */
#[RequiresMethod(ServiceCallMessage::class, 'getServiceId')]
final class ScheduledTaskRunMiddlewareTest extends TestCase
{
    private const TASK = 'app.cleanup::__invoke';

    private const SECOND = 1_000_000_000;

    private RecordingEventRecorder $recorder;

    private RecordingRunWriter $writer;

    private FakeClock $clock;

    private RecordingLogger $logger;

    #[Override]
    protected function setUp(): void
    {
        $this->recorder = new RecordingEventRecorder();
        $this->writer = new RecordingRunWriter();
        $this->clock = new FakeClock(5 * self::SECOND);
        $this->logger = new RecordingLogger();
    }

    public function testATaskHandledInlineIsOneRunWithItsOutcome(): void
    {
        $handled = $this->dispatch(new Envelope(new ServiceCallMessage('app.cleanup'), [new ReceivedStamp('sync')]));

        self::assertInstanceOf(ServiceCallMessage::class, $handled->getMessage());
        self::assertSame([['started', self::TASK], ['finished', self::TASK, ScheduledTaskRunOutcome::Success, 2.0]], $this->writer->calls);
        self::assertCount(1, $this->recorder->inputs);
        self::assertSame(Outcome::Success, $this->recorder->inputs[0]->observation->outcome);
    }

    public function testATaskFailingInlineIsAnErrorRunAndTheFailureReachesTheCaller(): void
    {
        $failure = new RuntimeException('boom');

        self::assertSame($failure, $this->failingDispatch(new Envelope(new ServiceCallMessage('app.cleanup')), handlerFailure: $failure));
        self::assertSame([['started', self::TASK], ['finished', self::TASK, ScheduledTaskRunOutcome::Error, 2.0]], $this->writer->calls);
        self::assertSame(Outcome::Error, $this->recorder->inputs[0]->observation->outcome);
    }

    /**
     * A transaction commit or a delayed message failing after the handler fails the dispatch, as it fails a worker run.
     */
    public function testAFailureOfAnOuterMiddlewareAfterTheHandlerIsAnError(): void
    {
        $failure = new RuntimeException('commit failed');

        self::assertSame($failure, $this->failingDispatch(new Envelope(new ServiceCallMessage('app.cleanup')), afterHandler: $failure));
        self::assertSame([['started', self::TASK], ['finished', self::TASK, ScheduledTaskRunOutcome::Error, 2.0]], $this->writer->calls);
        self::assertSame(Outcome::Error, $this->recorder->inputs[0]->observation->outcome);
    }

    /**
     * A task a handler dispatches with DispatchAfterCurrentBusStamp is handled after that handler, by continuing the
     * stack past the outcome middleware: its run still ends, with the outcome of its own handlers.
     */
    #[TestWith([false])]
    #[TestWith([true])]
    public function testATaskDispatchedAfterTheCurrentBusIsOneFinishedRun(bool $failing): void
    {
        $runs = $this->runs();
        $self = new class {
            public ?MessageBus $bus = null;
        };
        $handlers = new HandlersLocator([
            stdClass::class => [static function () use ($self): void {
                $self->bus?->dispatch(new ServiceCallMessage('app.cleanup'), [new DispatchAfterCurrentBusStamp()]);
            }],
            ServiceCallMessage::class => [function () use ($failing): void {
                $this->clock->nanoseconds += 2 * self::SECOND;
                if ($failing) {
                    throw new RuntimeException('boom');
                }
            }],
        ]);
        $self->bus = new MessageBus([new ScheduledTaskOutcomeMiddleware($runs), new DispatchAfterCurrentBusMiddleware(), new ScheduledTaskRunMiddleware($runs), new HandleMessageMiddleware($handlers)]);

        try {
            $self->bus->dispatch(new stdClass());
            self::assertFalse($failing);
        } catch (DelayedMessageHandlingException) {
            self::assertTrue($failing);
        }

        $outcome = $failing ? ScheduledTaskRunOutcome::Error : ScheduledTaskRunOutcome::Success;
        self::assertSame([['started', self::TASK], ['finished', self::TASK, $outcome, 2.0]], $this->writer->calls);
        self::assertCount(1, $this->recorder->inputs);
    }

    public function testATaskAWorkerConsumedIsStartedAndLeftToTheWorkerEvents(): void
    {
        $this->dispatch(new Envelope(new ServiceCallMessage('app.cleanup'), [new ReceivedStamp('async'), new ConsumedByWorkerStamp()]));

        self::assertSame([['started', self::TASK]], $this->writer->calls);
        self::assertSame([], $this->recorder->inputs);
    }

    public function testAFailingStartWriteStillFinishesTheInlineRun(): void
    {
        $this->writer = new RecordingRunWriter(failOnStarted: true);

        $this->dispatch(new Envelope(new ServiceCallMessage('app.cleanup')));

        self::assertSame([['finished', self::TASK, ScheduledTaskRunOutcome::Success, 2.0]], $this->writer->calls);
        self::assertCount(1, $this->recorder->inputs);
        self::assertSame([['warning', 'Scheduled task run was not recorded.']], $this->logger->records);
    }

    public function testOtherMessagesAndFailureTransportReplaysPassThroughUnmeasured(): void
    {
        foreach ([new Envelope(new stdClass()), new Envelope(new ServiceCallMessage('app.unmonitored')), new Envelope(new ServiceCallMessage('app.cleanup'), [new ReceivedStamp('failed')])] as $envelope) {
            $this->dispatch($envelope);
        }

        self::assertSame([], $this->writer->calls);
        self::assertSame([], $this->recorder->inputs);
    }

    private function dispatch(Envelope $envelope): Envelope
    {
        return $this->bus(null, null)->dispatch($envelope);
    }

    private function failingDispatch(Envelope $envelope, ?Throwable $handlerFailure = null, ?Throwable $afterHandler = null): Throwable
    {
        try {
            $this->bus($handlerFailure, $afterHandler)->dispatch($envelope);
        } catch (Throwable $caught) {
            return $caught;
        }
        self::fail('The failure must reach the caller.');
    }

    private function runs(): ScheduledTaskRuns
    {
        $catalog = CatalogFixture::build(static function (CatalogAssembler $assembler): void {
            $assembler->declare(ScheduledTaskPoint::forService('app.cleanup', '__invoke'), new Slo('cleanup.run', [new Availability(99)], deadline: '2m'));
        }, new CatalogExtras(failureTransports: ['failed']));

        return new ScheduledTaskRuns($catalog, $catalog, $this->recorder, $this->writer, new ExpectedFailureStub(), $this->clock, $this->logger);
    }

    /**
     * Outcome middleware, a user middleware that may fail after the handlers, run middleware, the handlers.
     */
    private function bus(?Throwable $handlerFailure, ?Throwable $afterHandler): MessageBus
    {
        $runs = $this->runs();
        $clock = $this->clock;

        return new MessageBus([
            new ScheduledTaskOutcomeMiddleware($runs),
            new class($afterHandler) implements MiddlewareInterface {
                public function __construct(private readonly ?Throwable $failure)
                {
                }

                #[Override]
                public function handle(Envelope $envelope, StackInterface $stack): Envelope
                {
                    $handled = $stack->next()->handle($envelope, $stack);
                    if ($this->failure instanceof Throwable) {
                        throw $this->failure;
                    }

                    return $handled;
                }
            },
            new ScheduledTaskRunMiddleware($runs),
            new class($handlerFailure, $clock) implements MiddlewareInterface {
                public function __construct(private readonly ?Throwable $failure, private readonly FakeClock $clock)
                {
                }

                #[Override]
                public function handle(Envelope $envelope, StackInterface $stack): Envelope
                {
                    $this->clock->nanoseconds += 2 * 1_000_000_000;
                    if ($this->failure instanceof Throwable) {
                        throw $this->failure;
                    }

                    return $envelope;
                }
            },
        ]);
    }
}
