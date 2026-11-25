<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\Entity;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Classification;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Deadline;
use Msstc4Symfony\SloBundle\Domain\ValueObject\LatencyThreshold;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Window;

final readonly class SloDefinition
{
    /**
     * @param non-empty-list<PointId> $points
     * @param non-empty-list<Objective> $objectives
     */
    public function __construct(
        private SloName $name,
        private PointType $pointType,
        private array $points,
        private array $objectives,
        private Window $window,
        private Classification $classification,
        private StrategyPreference $strategy,
        private ?Deadline $deadline = null,
    ) {
        $this->assertNotEmpty($points, 'point', $name);
        $this->assertNotEmpty($objectives, 'objective', $name);

        $seen = [];
        foreach ($points as $point) {
            if ($point->type !== $pointType) {
                throw new InvalidArgumentException(\sprintf('SLO "%s" is declared on "%s" points, but point "%s" was given.', $name->value, $pointType->value, $point->toString()));
            }
            if (isset($seen[$point->id])) {
                throw new InvalidArgumentException(\sprintf('SLO "%s" lists point "%s" twice.', $name->value, $point->toString()));
            }
            $seen[$point->id] = true;
        }

        $kinds = [];
        foreach ($objectives as $objective) {
            if (isset($kinds[$objective->kind->value])) {
                throw new InvalidArgumentException(\sprintf('SLO "%s" declares the "%s" objective twice.', $name->value, $objective->kind->value));
            }
            $kinds[$objective->kind->value] = true;
            if ($objective->includeErrors && !$pointType->acceptsIncludeErrors()) {
                throw new InvalidArgumentException(\sprintf('SLO "%s": includeErrors applies to http_route points only.', $name->value));
            }
        }

        $this->assertNeutralClassification($name, $pointType, $classification);
        $this->assertDeadline($name, $pointType);
    }

    public function name(): SloName
    {
        return $this->name;
    }

    public function pointType(): PointType
    {
        return $this->pointType;
    }

    /**
     * @return non-empty-list<PointId>
     */
    public function points(): array
    {
        return $this->points;
    }

    /**
     * @return non-empty-list<Objective>
     */
    public function objectives(): array
    {
        return $this->objectives;
    }

    public function window(): Window
    {
        return $this->window;
    }

    public function classification(): Classification
    {
        return $this->classification;
    }

    public function strategyPreference(): StrategyPreference
    {
        return $this->strategy;
    }

    public function deadline(): ?Deadline
    {
        return $this->deadline;
    }

    /**
     * The declared deadline, else the latency threshold, else the combined threshold; null off scheduled tasks.
     */
    public function runDeadline(): ?Deadline
    {
        if ($this->pointType !== PointType::ScheduledTask) {
            return null;
        }
        if ($this->deadline instanceof Deadline) {
            return $this->deadline;
        }

        foreach ([ObjectiveKind::Latency, ObjectiveKind::Combined] as $kind) {
            $threshold = $this->objective($kind)?->threshold;
            if ($threshold instanceof LatencyThreshold) {
                return Deadline::fromThreshold($threshold);
            }
        }

        return null;
    }

    public function objective(ObjectiveKind $kind): ?Objective
    {
        foreach ($this->objectives as $objective) {
            if ($objective->kind === $kind) {
                return $objective;
            }
        }

        return null;
    }

    public function withPoint(PointId $point): self
    {
        return new self(
            $this->name,
            $this->pointType,
            [...$this->points, $point],
            $this->objectives,
            $this->window,
            $this->classification,
            $this->strategy,
            $this->deadline,
        );
    }

    /**
     * The window is not part of the revision, so its spelling may change without splitting series.
     *
     * @internal discovery helper, not covered by semantic versioning
     */
    public function withWindow(Window $window): self
    {
        return new self(
            $this->name,
            $this->pointType,
            $this->points,
            $this->objectives,
            $window,
            $this->classification,
            $this->strategy,
            $this->deadline,
        );
    }

    public function sameSemanticsAs(self $other): bool
    {
        return $this->name->value === $other->name->value
            && $this->pointType === $other->pointType
            && $this->window->seconds() === $other->window->seconds()
            && $this->strategy === $other->strategy
            && $this->deadline?->milliseconds === $other->deadline?->milliseconds
            && $this->classification->equals($other->classification)
            && $this->sameObjectivesAs($other);
    }

    private function sameObjectivesAs(self $other): bool
    {
        if (\count($this->objectives) !== \count($other->objectives)) {
            return false;
        }

        foreach ($this->objectives as $objective) {
            $counterpart = $other->objective($objective->kind);
            if (!$counterpart instanceof Objective || !$objective->equals($counterpart)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Options that do not apply to the point type keep their defaults, so they never change the revision.
     */
    private function assertNeutralClassification(SloName $name, PointType $pointType, Classification $classification): void
    {
        $irrelevant = $classification->inapplicableFieldsFor($pointType);
        if ($irrelevant !== []) {
            throw new InvalidArgumentException(\sprintf('SLO "%s": %s must keep the default on %s points.', $name->value, implode(', ', $irrelevant), $pointType->value));
        }
    }

    private function assertDeadline(SloName $name, PointType $pointType): void
    {
        if ($pointType !== PointType::ScheduledTask) {
            if ($this->deadline instanceof Deadline) {
                throw new InvalidArgumentException(\sprintf('SLO "%s": deadline applies to scheduled_task points only.', $name->value));
            }

            return;
        }
        if (!$this->runDeadline() instanceof Deadline) {
            throw new InvalidArgumentException(\sprintf('SLO "%s": scheduled_task SLOs need a deadline or a latency objective.', $name->value));
        }
    }

    /**
     * @param list<object> $items
     */
    private function assertNotEmpty(array $items, string $what, SloName $name): void
    {
        if ($items === []) {
            throw new InvalidArgumentException(\sprintf('SLO "%s" needs at least one %s.', $name->value, $what));
        }
    }
}
