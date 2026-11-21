<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Evaluation\RecordEvent;

use Msstc4Symfony\SloBundle\Application\Port\EventWriterInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\EventResult;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Override;
use RuntimeException;

final class ThrowingEventWriter implements EventWriterInterface
{
    #[Override]
    public function write(SloName $slo, ObjectiveKind $kind, Revision $revision, EventResult $result): void
    {
        throw new RuntimeException('storage down');
    }
}
