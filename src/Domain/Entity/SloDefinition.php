<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\Entity;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Classification;
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
            if ($objective->includeErrors && $pointType !== PointType::HttpRoute) {
                throw new InvalidArgumentException(\sprintf('SLO "%s": includeErrors applies to http_route points only.', $name->value));
            }
        }

        $this->assertNeutralClassification($name, $pointType, $classification);
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

    public function strategy(): StrategyPreference
    {
        return $this->strategy;
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
        );
    }

    public function sameSemanticsAs(self $other): bool
    {
        return $this->name->value === $other->name->value
            && $this->pointType === $other->pointType
            && $this->window->seconds() === $other->window->seconds()
            && $this->strategy === $other->strategy
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
        $neutral = Classification::defaults();
        $irrelevant = [];
        if ($pointType !== PointType::HttpRoute && $classification->badStatuses->canonical() !== $neutral->badStatuses->canonical()) {
            $irrelevant[] = 'badStatuses';
        }
        if ($pointType !== PointType::HttpRoute && $classification->ineligibleStatuses->canonical() !== $neutral->ineligibleStatuses->canonical()) {
            $irrelevant[] = 'ineligibleStatuses';
        }
        if ($pointType !== PointType::LogicAction && $pointType !== PointType::Operation && $classification->expectedFailureIsGood !== $neutral->expectedFailureIsGood) {
            $irrelevant[] = 'expectedFailureIsGood';
        }
        if ($pointType !== PointType::MessengerMessage && $classification->countAttempts !== $neutral->countAttempts) {
            $irrelevant[] = 'countAttempts';
        }
        if ($irrelevant !== []) {
            throw new InvalidArgumentException(\sprintf('SLO "%s": %s must keep the default on %s points.', $name->value, implode(', ', $irrelevant), $pointType->value));
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
