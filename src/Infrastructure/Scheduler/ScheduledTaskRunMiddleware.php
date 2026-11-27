<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Scheduler;

use Override;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Throwable;

/**
 * Starts a scheduled-task run where the bus hands the message to its handlers (inserted right before
 * `handle_message`), so a message that is sent elsewhere, skipped or refused is no run. The outcome comes from
 * ScheduledTaskRunListener for what a worker consumed and from ScheduledTaskOutcomeMiddleware for an inline dispatch
 * it wraps; a message it does not wrap (DispatchAfterCurrentBusStamp, handled later by continuing the stack) gets the
 * outcome of its handlers here.
 *
 * @internal
 */
final readonly class ScheduledTaskRunMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ScheduledTaskRuns $runs,
    ) {
    }

    #[Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $this->runs->start($envelope);
        $message = $envelope->getMessage();
        if ($envelope->last(ConsumedByWorkerStamp::class) instanceof ConsumedByWorkerStamp || $this->runs->isObserved($message)) {
            return $stack->next()->handle($envelope, $stack);
        }

        try {
            $handled = $stack->next()->handle($envelope, $stack);
        } catch (Throwable $failure) {
            $this->runs->failed($message, $failure, false);

            throw $failure;
        }
        $this->runs->succeeded($message);

        return $handled;
    }
}
