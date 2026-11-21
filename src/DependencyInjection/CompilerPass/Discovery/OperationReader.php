<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Combined;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\ObjectiveAttributeInterface;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;

/**
 * Maps the already normalised `operations` config onto #[Slo] values, so one factory validates both sources.
 *
 * @internal
 */
final readonly class OperationReader
{
    public function __construct(private CatalogAssembler $assembler)
    {
    }

    /**
     * @return list<non-empty-string> operation names, sorted
     */
    public function read(mixed $operations): array
    {
        $names = [];
        foreach (\is_array($operations) ? $operations : [] as $name => $config) {
            if (!\is_string($name) || $name === '' || !\is_array($config)) {
                continue;
            }
            $objectives = [];
            foreach (\is_array($config['objectives'] ?? null) ? $config['objectives'] : [] as $objective) {
                if (\is_array($objective)) {
                    $objectives[] = $this->objective($objective);
                }
            }
            if ($objectives === []) {
                continue;
            }
            $window = $config['window'] ?? null;
            $expectedFailureIsGood = $config['expected_failure_is_good'] ?? null;
            $this->assembler->declare(PointId::of(PointType::Operation, $name), new Slo(
                name: $name,
                objectives: $objectives,
                window: \is_string($window) ? $window : null,
                expectedFailureIsGood: \is_bool($expectedFailureIsGood) ? $expectedFailureIsGood : null,
                strategy: 'dedicated',
            ));
            $names[] = $name;
        }
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * @param array<array-key, mixed> $objective
     */
    private function objective(array $objective): ObjectiveAttributeInterface
    {
        $target = $objective['target'] ?? null;
        $target = \is_float($target) || \is_int($target) ? (float) $target : 0.0;

        $threshold = $objective['threshold'] ?? null;
        $threshold = \is_string($threshold) ? $threshold : '';

        return match ($objective['type'] ?? null) {
            'latency' => new Latency($threshold, $target, ($objective['include_errors'] ?? false) === true),
            'combined' => new Combined($threshold, $target),
            default => new Availability($target),
        };
    }
}
