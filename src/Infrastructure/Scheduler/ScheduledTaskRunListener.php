<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Scheduler;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * The outcome of a scheduled-task run a worker consumed; ScheduledTaskRunMiddleware started it.
 *
 * @internal
 */
final readonly class ScheduledTaskRunListener
{
    public function __construct(
        private ScheduledTaskRuns $runs,
    ) {
    }

    #[AsEventListener(event: WorkerMessageHandledEvent::class)]
    public function onHandled(WorkerMessageHandledEvent $event): void
    {
        $this->runs->succeeded($event->getEnvelope()->getMessage());
    }

    /**
     * Runs at 0, after the retry listener (100) has decided willRetry().
     */
    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function onFailed(WorkerMessageFailedEvent $event): void
    {
        $this->runs->failed($event->getEnvelope()->getMessage(), $event->getThrowable(), $event->willRetry());
    }
}
