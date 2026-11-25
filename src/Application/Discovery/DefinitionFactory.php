<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Discovery;

use Closure;
use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Combined;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Classification;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Deadline;
use Msstc4Symfony\SloBundle\Domain\ValueObject\LatencyThreshold;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Domain\ValueObject\StatusSet;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Target;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Window;

/**
 * Turns raw #[Slo] values plus the configured defaults into a single-point SloDefinition. Every problem is
 * reported to DiscoveryErrors prefixed with the point, so one compilation lists all of them.
 *
 * Classification fields that do not apply to a point type take the fixed values of Classification::defaults(),
 * so changing an unrelated default never changes a revision.
 *
 * @internal
 */
final readonly class DefinitionFactory
{
    /**
     * @param list<int|string> $httpBadStatuses
     * @param list<int|string> $httpIneligibleStatuses
     */
    public function __construct(
        private string $defaultWindow,
        private array $httpBadStatuses,
        private array $httpIneligibleStatuses,
        private bool $expectedFailureIsGood,
        private bool $countAttempts,
    ) {
    }

    public function fromAttribute(Slo $slo, PointId $point, DiscoveryErrors $errors): ?SloDefinition
    {
        $failed = false;
        $fail = static function (string $message) use ($point, $errors, &$failed): void {
            $errors->add($point->toString() . ': ' . $message);
            $failed = true;
        };
        $option = static function (string $message) use ($fail, $slo): void {
            $fail(\sprintf('SLO "%s": %s', $slo->name, $message));
        };

        $name = $this->attempt(static fn (): SloName => SloName::fromString($slo->name), $fail);
        $window = $this->attempt(fn (): Window => Window::fromString($slo->window ?? $this->defaultWindow), $fail);
        $objectives = $this->objectives($slo, $point->type, $fail, $option);
        $classification = $this->classification($slo, $point->type, $fail, $option);
        $deadline = $this->deadline($slo, $point->type, $fail, $option);

        if ($failed || !$name instanceof SloName || !$window instanceof Window || !$classification instanceof Classification) {
            return null;
        }
        if ($objectives === []) {
            $option('at least one objective is required.');

            return null;
        }

        return $this->attempt(
            static fn (): SloDefinition => new SloDefinition($name, $point->type, [$point], $objectives, $window, $classification, $slo->strategy, $deadline),
            $fail,
        );
    }

    /**
     * @param Closure(string): void $fail
     * @param Closure(string): void $option
     */
    private function deadline(Slo $slo, PointType $type, Closure $fail, Closure $option): ?Deadline
    {
        if ($type !== PointType::ScheduledTask) {
            if ($slo->deadline !== null) {
                $option('deadline applies to scheduled_task points only.');
            }

            return null;
        }

        if ($slo->deadline === null) {
            if (array_filter($slo->objectives, static fn (object $o): bool => $o instanceof Latency || $o instanceof Combined) === []) {
                $option('scheduled_task SLOs need a deadline or a latency objective.');
            }

            return null;
        }

        $declared = $slo->deadline;
        $deadline = $this->attempt(static fn (): Deadline => Deadline::fromString($declared), $fail);

        return $deadline instanceof Deadline ? $deadline : null;
    }

    /**
     * @param Closure(string): void $fail
     * @param Closure(string): void $option
     *
     * @return list<Objective>
     */
    private function objectives(Slo $slo, PointType $type, Closure $fail, Closure $option): array
    {
        $objectives = [];
        foreach ($slo->objectives as $declared) {
            if ($declared instanceof Latency && $declared->includeErrors && !$type->acceptsIncludeErrors()) {
                $option('includeErrors applies to http_route points only.');

                continue;
            }
            $build = match (true) {
                $declared instanceof Availability => static fn (): Objective => Objective::availability(Target::fromPercent($declared->target)),
                $declared instanceof Latency => static fn (): Objective => Objective::latency(LatencyThreshold::fromString($declared->threshold), Target::fromPercent($declared->target), $declared->includeErrors),
                $declared instanceof Combined => static fn (): Objective => Objective::combined(LatencyThreshold::fromString($declared->threshold), Target::fromPercent($declared->target)),
                default => null,
            };
            if ($build === null) {
                $option(\sprintf('unsupported objective %s.', $declared::class));

                continue;
            }
            $objective = $this->attempt($build, $fail);
            if ($objective instanceof Objective) {
                $objectives[] = $objective;
            }
        }

        return $objectives;
    }

    /**
     * @param Closure(string): void $fail
     * @param Closure(string): void $option
     */
    private function classification(Slo $slo, PointType $type, Closure $fail, Closure $option): ?Classification
    {
        $declared = [
            'badStatuses' => $slo->badStatuses,
            'ineligibleStatuses' => $slo->ineligibleStatuses,
            'countAttempts' => $slo->countAttempts,
            'expectedFailureIsGood' => $slo->expectedFailureIsGood,
        ];
        foreach ($declared as $field => $value) {
            if ($value !== null && !Classification::appliesTo($field, $type)) {
                $option(\sprintf('%s applies to %s points only.', $field, $this->joinTypes(Classification::pointTypesOf($field))));
            }
        }

        $neutral = Classification::defaults();

        return $this->attempt(fn (): Classification => new Classification(
            Classification::appliesTo('badStatuses', $type) ? StatusSet::fromList($slo->badStatuses ?? $this->httpBadStatuses) : $neutral->badStatuses,
            Classification::appliesTo('ineligibleStatuses', $type) ? StatusSet::fromList($slo->ineligibleStatuses ?? $this->httpIneligibleStatuses) : $neutral->ineligibleStatuses,
            Classification::appliesTo('expectedFailureIsGood', $type) ? $slo->expectedFailureIsGood ?? $this->expectedFailureIsGood : $neutral->expectedFailureIsGood,
            Classification::appliesTo('countAttempts', $type) ? $slo->countAttempts ?? $this->countAttempts : $neutral->countAttempts,
        ), $fail);
    }

    /**
     * @template T of object
     *
     * @param Closure(): T $build
     * @param Closure(string): void $fail
     *
     * @return T|null
     */
    private function attempt(Closure $build, Closure $fail): ?object
    {
        try {
            return $build();
        } catch (InvalidArgumentException $e) {
            $fail($e->getMessage());

            return null;
        }
    }

    /**
     * @param non-empty-list<PointType> $types
     */
    private function joinTypes(array $types): string
    {
        $names = array_map(static fn (PointType $t): string => $t->value, $types);
        $last = array_pop($names);

        return $names === [] ? $last : implode(', ', $names) . ' and ' . $last;
    }
}
