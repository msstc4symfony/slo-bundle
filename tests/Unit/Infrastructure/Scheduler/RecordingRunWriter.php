<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Scheduler;

use Msstc4Symfony\SloBundle\Application\Port\ScheduledTaskRunOutcome;
use Msstc4Symfony\SloBundle\Application\Port\ScheduledTaskRunWriterInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Override;
use RuntimeException;

final class RecordingRunWriter implements ScheduledTaskRunWriterInterface
{
    /** @var list<array{'started', string}|array{'finished', string, ScheduledTaskRunOutcome, float}> */
    public array $calls = [];

    public function __construct(private readonly bool $failOnStarted = false, private readonly bool $failOnFinished = false)
    {
    }

    #[Override]
    public function started(PointId $task): void
    {
        if ($this->failOnStarted) {
            throw new RuntimeException('storage down');
        }
        $this->calls[] = ['started', $task->id];
    }

    #[Override]
    public function finished(PointId $task, ScheduledTaskRunOutcome $outcome, float $durationSeconds): void
    {
        if ($this->failOnFinished) {
            throw new RuntimeException('storage down');
        }
        $this->calls[] = ['finished', $task->id, $outcome, $durationSeconds];
    }
}
