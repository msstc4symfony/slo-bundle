<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\Validate;

use Msstc4Symfony\SloBundle\Application\Catalog\Validate\DeclarationDiff;
use Msstc4Symfony\SloBundle\Application\Catalog\Validate\FieldDifference;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Classification;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Deadline;
use Msstc4Symfony\SloBundle\Domain\ValueObject\LatencyThreshold;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Target;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Window;
use PHPUnit\Framework\TestCase;

final class DeclarationDiffTest extends TestCase
{
    public function testDeadlineEqualToTheLatencyThresholdIsNoDifference(): void
    {
        self::assertSame([], DeclarationDiff::between($this->task(null), $this->task(Deadline::fromString('2m'))));
    }

    public function testDifferentEffectiveDeadlinesAreReported(): void
    {
        $differences = DeclarationDiff::between($this->task(null), $this->task(Deadline::fromString('5m')));

        self::assertEquals([new FieldDifference('deadline', '2m', '5m')], $differences);
    }

    private function task(?Deadline $deadline): SloDefinition
    {
        return new SloDefinition(
            SloName::fromString('cleanup.run'),
            PointType::ScheduledTask,
            [PointId::of(PointType::ScheduledTask, 'app.cleanup::__invoke')],
            [Objective::latency(LatencyThreshold::fromString('120s'), Target::fromPercent(95.0))],
            Window::fromString('30d'),
            Classification::defaults(),
            StrategyPreference::Auto,
            $deadline,
        );
    }
}
