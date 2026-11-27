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
 * The outcome of a scheduled-task run handled inline (a `sync://` transport, a task with `transports: 'sync'`, an
 * unrouted dispatch), first in every bus: the whole dispatch decides it, as on a worker, so a failing transaction
 * commit or delayed message after a successful handler is an error. What a worker consumed is left to
 * ScheduledTaskRunListener, which knows about retries. The failure reaches the caller unchanged.
 *
 * @internal
 */
final readonly class ScheduledTaskOutcomeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private ScheduledTaskRuns $runs,
    ) {
    }

    #[Override]
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        $message = $envelope->getMessage();
        if ($envelope->last(ConsumedByWorkerStamp::class) instanceof ConsumedByWorkerStamp || !ScheduledTaskMessage::isTaskMessage($message)) {
            return $stack->next()->handle($envelope, $stack);
        }

        $this->runs->observe($message);
        try {
            $dispatched = $stack->next()->handle($envelope, $stack);
        } catch (Throwable $failure) {
            $this->runs->release($message);
            $this->runs->failed($message, $failure, false);

            throw $failure;
        }
        $this->runs->release($message);
        $this->runs->succeeded($message);

        return $dispatched;
    }
}
