<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;

/**
 * Below every priority the bundle could pick: a refused message must still be no run.
 */
final class RefusingWorkerListener
{
    public static bool $refusing = false;

    #[AsEventListener(priority: -100000)]
    public function __invoke(WorkerMessageReceivedEvent $event): void
    {
        if (self::$refusing) {
            $event->shouldHandle(false);
        }
    }
}
