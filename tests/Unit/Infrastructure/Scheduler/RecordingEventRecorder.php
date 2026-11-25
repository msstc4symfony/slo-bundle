<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Scheduler;

use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Input;
use Msstc4Symfony\SloBundle\Application\Port\EventRecorderInterface;
use Override;

final class RecordingEventRecorder implements EventRecorderInterface
{
    /** @var list<Input> */
    public array $inputs = [];

    #[Override]
    public function record(Input $input): void
    {
        $this->inputs[] = $input;
    }
}
