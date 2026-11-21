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
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Classification;
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
        $strategy = StrategyPreference::tryFrom($slo->strategy);
        if (!$strategy instanceof StrategyPreference) {
            $option(\sprintf('invalid strategy "%s", expected auto|dedicated.', $slo->strategy));
        }
        $objectives = $this->objectives($slo, $point->type, $fail, $option);
        $classification = $this->classification($slo, $point->type, $fail, $option);

        if ($failed || !$name instanceof SloName || !$window instanceof Window || !$strategy instanceof StrategyPreference || !$classification instanceof Classification) {
            return null;
        }
        if ($objectives === []) {
            $option('at least one objective is required.');

            return null;
        }

        return $this->attempt(
            static fn (): SloDefinition => new SloDefinition($name, $point->type, [$point], $objectives, $window, $classification, $strategy),
            $fail,
        );
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
            if ($declared instanceof Latency && $declared->includeErrors && $type !== PointType::HttpRoute) {
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
        $isHttp = $type === PointType::HttpRoute;
        $isMessenger = $type === PointType::MessengerMessage;
        $hasOutcomes = $type === PointType::LogicAction || $type === PointType::Operation;

        if (!$isHttp && $slo->badStatuses !== null) {
            $option('badStatuses applies to http_route points only.');
        }
        if (!$isHttp && $slo->ineligibleStatuses !== null) {
            $option('ineligibleStatuses applies to http_route points only.');
        }
        if (!$isMessenger && $slo->countAttempts !== null) {
            $option('countAttempts applies to messenger_message points only.');
        }
        if (!$hasOutcomes && $slo->expectedFailureIsGood !== null) {
            $option('expectedFailureIsGood applies to logic_action and operation points only.');
        }

        $neutral = Classification::defaults();

        return $this->attempt(fn (): Classification => new Classification(
            $isHttp ? StatusSet::fromList($slo->badStatuses ?? $this->httpBadStatuses) : $neutral->badStatuses,
            $isHttp ? StatusSet::fromList($slo->ineligibleStatuses ?? $this->httpIneligibleStatuses) : $neutral->ineligibleStatuses,
            $hasOutcomes ? $slo->expectedFailureIsGood ?? $this->expectedFailureIsGood : $neutral->expectedFailureIsGood,
            $isMessenger ? $slo->countAttempts ?? $this->countAttempts : $neutral->countAttempts,
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
}
