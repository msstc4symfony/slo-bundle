<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Scheduler;

use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Input;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\EventRecorderInterface;
use Msstc4Symfony\SloBundle\Application\Port\ExpectedFailureClassifierInterface;
use Msstc4Symfony\SloBundle\Application\Port\MonotonicClockInterface;
use Msstc4Symfony\SloBundle\Application\Port\ScheduledTaskRunOutcome;
use Msstc4Symfony\SloBundle\Application\Port\ScheduledTaskRunWriterInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\Outcome;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Observation;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Exception\RunCommandFailedException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;
use WeakMap;

/**
 * Times scheduled-task runs with its own clock (the metrics-bundle series does not identify the task), writes run
 * metrics for every attempt and passes the final outcome to the recorder; EventClassifier drops runs the generated
 * rules already counted as unfinished. A run starts when the bus hands the message to its handlers
 * (ScheduledTaskRunMiddleware), so a message a worker listener refused, or a middleware skipped, is no run.
 * Fail-open: nothing leaves it.
 *
 * @internal
 */
final class ScheduledTaskRuns implements ResetInterface
{
    private const NOT_RECORDED = 'Scheduled task run was not recorded.';

    /**
     * Pending starts keyed by the message object, which Envelope::with() keeps.
     *
     * @var WeakMap<object, array{PointId, int}>
     */
    private WeakMap $pending;

    /**
     * Messages whose dispatch an outcome middleware is wrapping right now; nested dispatches of one object count.
     *
     * @var WeakMap<object, positive-int>
     */
    private WeakMap $observed;

    public function __construct(
        private readonly SloCatalogInterface $catalog,
        private readonly CatalogDiagnosticsInterface $diagnostics,
        private readonly EventRecorderInterface $recorder,
        private readonly ScheduledTaskRunWriterInterface $runs,
        private readonly ExpectedFailureClassifierInterface $classifier,
        private readonly MonotonicClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        $this->pending = new WeakMap();
        $this->observed = new WeakMap();
    }

    /**
     * A replay from a failure transport is no run: it is no SLO event, and a replay that hangs must not count as an
     * unfinished run of the task. A failing `started` write still leaves the run to be finished.
     */
    public function start(Envelope $envelope): void
    {
        $point = null;
        try {
            $message = $envelope->getMessage();
            $point = ScheduledTaskMessage::pointOf($message);
            if (!$point instanceof PointId || !$this->isMonitored($point) || $this->fromFailureTransport($envelope)) {
                return;
            }
            $this->pending[$message] = [$point, $this->clock->now()];
        } catch (Throwable $e) {
            $this->warn($point, $e);

            return;
        }
        try {
            $this->runs->started($point);
        } catch (Throwable $e) {
            $this->warn($point, $e);
        }
    }

    public function succeeded(object $message): void
    {
        try {
            $run = $this->take($message);
            if ($run !== null) {
                $this->finish($run, ScheduledTaskRunOutcome::Success);
            }
        } catch (Throwable $e) {
            $this->warn(null, $e);
        }
    }

    public function failed(object $message, Throwable $failure, bool $willRetry): void
    {
        try {
            $run = $this->take($message);
            if ($run !== null) {
                $this->finish($run, $willRetry ? ScheduledTaskRunOutcome::Retried : $this->classify($run[0], $failure));
            }
        } catch (Throwable $e) {
            $this->warn(null, $e);
        }
    }

    /**
     * ScheduledTaskOutcomeMiddleware wraps the whole dispatch of this message and reports its outcome.
     */
    public function observe(object $message): void
    {
        $this->observed[$message] = ($this->observed[$message] ?? 0) + 1;
    }

    public function release(object $message): void
    {
        $depth = ($this->observed[$message] ?? 1) - 1;
        if ($depth > 0) {
            $this->observed[$message] = $depth;
        } else {
            unset($this->observed[$message]);
        }
    }

    /**
     * False for a message handled where no outcome middleware wraps it, such as a DispatchAfterCurrentBusStamp
     * message, which the bus handles later by continuing the stack after `dispatch_after_current_bus`.
     */
    public function isObserved(object $message): bool
    {
        return isset($this->observed[$message]);
    }

    #[Override]
    public function reset(): void
    {
        $this->pending = new WeakMap();
        $this->observed = new WeakMap();
    }

    private function isMonitored(PointId $point): bool
    {
        return $this->catalog->forPoint($point) !== [] || $this->catalog->conflictedNamesFor($point) !== [];
    }

    /**
     * FailedMessageProcessingMiddleware renames the last ReceivedStamp of a replay to the original transport, so a
     * replay is told by SentToFailureTransportStamp or by any receipt from a failure transport.
     */
    private function fromFailureTransport(Envelope $envelope): bool
    {
        if ($envelope->last(SentToFailureTransportStamp::class) instanceof SentToFailureTransportStamp) {
            return true;
        }
        $failureTransports = $this->diagnostics->failureTransports();
        foreach ($envelope->all(ReceivedStamp::class) as $received) {
            if ($received instanceof ReceivedStamp && \in_array($received->getTransportName(), $failureTransports, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{PointId, float}|null the task and the run duration in seconds, null without a recorded start
     */
    private function take(object $message): ?array
    {
        $start = $this->pending[$message] ?? null;
        if ($start === null) {
            return null;
        }
        unset($this->pending[$message]);

        return [$start[0], ($this->clock->now() - $start[1]) / 1e9];
    }

    /**
     * @param array{PointId, float} $run
     */
    private function finish(array $run, ScheduledTaskRunOutcome $outcome): void
    {
        [$point, $duration] = $run;
        $sloOutcome = $this->sloOutcome($outcome);
        if ($sloOutcome instanceof Outcome) {
            try {
                $this->recorder->record(new Input($point, Observation::outcome(PointType::ScheduledTask, $sloOutcome, $duration)));
            } catch (Throwable $e) {
                $this->warn($point, $e);
            }
        }
        try {
            $this->runs->finished($point, $outcome, $duration);
        } catch (Throwable $e) {
            $this->warn($point, $e);
        }
    }

    /**
     * A command task always fails as an error: RunCommandMessageHandler wraps whatever the command threw.
     */
    private function classify(PointId $point, Throwable $failure): ScheduledTaskRunOutcome
    {
        $failure = $failure instanceof HandlerFailedException ? ($failure->getPrevious() ?? $failure) : $failure;
        if ($failure instanceof RunCommandFailedException) {
            return ScheduledTaskRunOutcome::Error;
        }
        try {
            return $this->classifier->isExpectedFailure($failure) ? ScheduledTaskRunOutcome::ExpectedFailure : ScheduledTaskRunOutcome::Error;
        } catch (Throwable $e) {
            $this->logger->warning('Scheduled task exception could not be classified, it counts as an error.', ['task' => $point->id, 'exception' => $e]);

            return ScheduledTaskRunOutcome::Error;
        }
    }

    /**
     * A retried attempt is not a final outcome: no SLO event.
     */
    private function sloOutcome(ScheduledTaskRunOutcome $outcome): ?Outcome
    {
        return match ($outcome) {
            ScheduledTaskRunOutcome::Success => Outcome::Success,
            ScheduledTaskRunOutcome::ExpectedFailure => Outcome::ExpectedFailure,
            ScheduledTaskRunOutcome::Error => Outcome::Error,
            ScheduledTaskRunOutcome::Retried => null,
        };
    }

    private function warn(?PointId $point, Throwable $e): void
    {
        $this->logger->warning(self::NOT_RECORDED, ['task' => $point?->id, 'exception' => $e]);
    }
}
