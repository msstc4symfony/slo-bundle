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
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\AbstractWorkerMessageEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;
use WeakMap;

/**
 * Times scheduled-task runs consumed by a worker with its own clock (the metrics-bundle series does not identify the
 * task), writes run metrics for every attempt and one SLO event for the final outcome of a run within its deadline.
 * Fail-open: nothing leaves it.
 *
 * @internal
 */
final class ScheduledTaskRunListener implements ResetInterface
{
    private const NOT_RECORDED = 'Scheduled task run was not recorded.';

    /**
     * Pending starts keyed by the message object, which Envelope::with() keeps.
     *
     * @var WeakMap<object, array{PointId, int}>
     */
    private WeakMap $pending;

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
    }

    /**
     * Runs after other listeners to honour their shouldHandle(); the failed listener runs at 0, after the retry
     * listener (100) has decided willRetry().
     */
    #[AsEventListener(event: WorkerMessageReceivedEvent::class, priority: -1000)]
    public function onReceived(WorkerMessageReceivedEvent $event): void
    {
        $point = null;
        try {
            if (!$event->shouldHandle()) {
                return;
            }
            $message = $event->getEnvelope()->getMessage();
            $point = ScheduledTaskMessage::pointOf($message);
            if (!$point instanceof PointId || !$this->isMonitored($point)) {
                return;
            }
            $this->pending[$message] = [$point, $this->clock->now()];
            $this->runs->started($point);
        } catch (Throwable $e) {
            $this->warn($point, $e);
        }
    }

    #[AsEventListener(event: WorkerMessageHandledEvent::class)]
    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        try {
            $run = $this->takeRun($event);
            if ($run !== null) {
                $this->finish($event, $run, ScheduledTaskRunOutcome::Success);
            }
        } catch (Throwable $e) {
            $this->warn(null, $e);
        }
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        try {
            $run = $this->takeRun($event);
            if ($run !== null) {
                $this->finish($event, $run, $event->willRetry() ? ScheduledTaskRunOutcome::Retried : $this->classify($run[0], $event->getThrowable()));
            }
        } catch (Throwable $e) {
            $this->warn(null, $e);
        }
    }

    #[Override]
    public function reset(): void
    {
        $this->pending = new WeakMap();
    }

    private function isMonitored(PointId $point): bool
    {
        return $this->catalog->forPoint($point) !== [] || $this->catalog->conflictedNamesFor($point) !== [];
    }

    /**
     * @return array{PointId, float}|null the task and the run duration in seconds, null without a recorded start
     */
    private function takeRun(AbstractWorkerMessageEvent $event): ?array
    {
        $message = $event->getEnvelope()->getMessage();
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
    private function finish(AbstractWorkerMessageEvent $event, array $run, ScheduledTaskRunOutcome $outcome): void
    {
        [$point, $duration] = $run;
        $sloOutcome = $this->sloOutcome($outcome);
        if ($sloOutcome instanceof Outcome) {
            try {
                if (!$this->isPastEveryDeadline($point, $duration)) {
                    $onFailureTransport = \in_array($event->getReceiverName(), $this->diagnostics->failureTransports(), true);
                    $this->recorder->record(new Input($point, Observation::outcome(PointType::ScheduledTask, $sloOutcome, $duration, $onFailureTransport)));
                }
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
     * The rules already counted such a run as unfinished at start + deadline; EventClassifier skips it per SLO too.
     */
    private function isPastEveryDeadline(PointId $point, float $duration): bool
    {
        $definitions = $this->catalog->forPoint($point);
        foreach ($definitions as $definition) {
            if (!$definition->isPastRunDeadline($duration)) {
                return false;
            }
        }

        return $definitions !== [];
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
