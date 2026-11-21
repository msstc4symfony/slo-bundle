<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Evaluation\RecordEvent;

use Msstc4Symfony\SloBundle\Application\Port\EventWriterInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\EventResult;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Override;

final class InMemoryEventWriter implements EventWriterInterface
{
    /** @var list<array{string, string, string, string}> */
    public array $writes = [];

    #[Override]
    public function write(SloName $slo, ObjectiveKind $kind, Revision $revision, EventResult $result): void
    {
        $this->writes[] = [$slo->value, $kind->value, $revision->value, $result->value];
    }
}
