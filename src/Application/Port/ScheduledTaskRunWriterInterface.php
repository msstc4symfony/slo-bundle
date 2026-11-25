<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;

/**
 * Per-task run metrics, written independently of the SLO events. May throw: the caller is fail-open.
 *
 * @internal
 */
interface ScheduledTaskRunWriterInterface
{
    public function started(PointId $task): void;

    public function finished(PointId $task, ScheduledTaskRunOutcome $outcome, float $durationSeconds): void;
}
