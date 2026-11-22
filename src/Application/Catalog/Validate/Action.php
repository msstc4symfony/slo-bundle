<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Catalog\Validate;

use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Discovery\SloDeclaration;
use Msstc4Symfony\SloBundle\Application\Generation\BurnRatePolicy;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateRules\Action as GenerateRules;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;

/**
 * @internal
 */
final readonly class Action
{
    public function __construct(
        private SloCatalogInterface $catalog,
        private CatalogDiagnosticsInterface $diagnostics,
        private RouteCatalogInterface $routes,
        private MetricBindingResolver $bindings,
        private BurnRatePolicy $burnRates = new BurnRatePolicy(),
        private bool $prometheusRules = true,
        private bool $prometheusAlerts = true,
    ) {
    }

    public function __invoke(): Output
    {
        $errors = [];
        $notices = [];

        foreach ($this->diagnostics->conflicts() as $name => $declarations) {
            foreach (\array_slice($declarations, 1) as $other) {
                $errors[] = $this->conflictMessage($name, $declarations[0], $other);
            }
        }

        foreach ($this->diagnostics->shared() as $name => $points) {
            $notices[] = \sprintf(
                'SLO %s is shared by %d points: %s',
                $name,
                \count($points),
                implode(', ', array_map(static fn (PointId $point): string => $point->toString(), $points)),
            );
        }

        foreach ($this->catalog->all() as $definition) {
            if ($definition->strategyPreference() !== StrategyPreference::Auto) {
                continue;
            }
            $objective = $definition->objective(ObjectiveKind::Latency);
            if ($objective !== null && $this->bindings->thresholdMissesBucket($definition, $objective)) {
                $notices[] = \sprintf(
                    'SLO %s: latency threshold %s is not a bucket of %s, uses dedicated counters',
                    $definition->name()->value,
                    $objective->threshold?->toString() ?? '-',
                    $this->bindings->histogramFor($definition->pointType()) ?? '-',
                );
            }
        }

        $warnings = [...$this->diagnostics->extras()->warnings, ...GenerateRules::configurationWarnings($this->prometheusRules, $this->prometheusAlerts)];
        foreach ($this->catalog->all() as $definition) {
            foreach ($definition->objectives() as $objective) {
                array_push($warnings, ...$this->burnRates->warnings($definition->name()->value, $objective->kind->value, $definition->window(), $objective->target));
            }
        }

        array_push($warnings, ...$this->syncOnlyWarnings());

        $ignored = $this->diagnostics->extras()->httpIgnoredRoutes;
        foreach ($this->catalog->all() as $definition) {
            if ($definition->pointType() !== PointType::HttpRoute) {
                continue;
            }
            $name = $definition->name()->value;
            foreach ($definition->points() as $point) {
                $routes = $this->routes->routesFor($point->id);
                if ($routes === []) {
                    $errors[] = \sprintf('SLO %s: %s has no route', $name, $point->id);
                }
                foreach ($routes as $route) {
                    if (\in_array($route->name, $ignored, true)) {
                        $errors[] = \sprintf('SLO %s: route %s of %s is in the ignored routes of metrics-bundle (http.ignored_routes), it is never measured', $name, $route->name, $point->id);
                    }
                }
            }
        }

        return new Output(
            $this->sorted($errors),
            $this->sorted($warnings),
            $this->sorted($notices),
        );
    }

    /**
     * metrics-bundle measures worker deliveries only, so an SLO on a message no worker consumes is dead.
     *
     * @return list<non-empty-string>
     */
    private function syncOnlyWarnings(): array
    {
        $async = [];
        foreach ($this->diagnostics->extras()->handlers as $handler) {
            $async[$handler->messageClass] = ($async[$handler->messageClass] ?? false) || $handler->async;
        }
        $warnings = [];
        foreach ($this->catalog->all() as $definition) {
            if ($definition->pointType() !== PointType::MessengerMessage) {
                continue;
            }
            foreach ($definition->points() as $point) {
                if (($async[$point->id] ?? true) === false) {
                    $warnings[] = \sprintf('SLO %s: %s is not consumed by a worker (routed nowhere or only to sync://); sync handling is not measured, the SLO never records events', $definition->name()->value, $point->toString());
                }
            }
        }

        return $warnings;
    }

    /**
     * @return non-empty-string
     */
    private function conflictMessage(string $name, SloDeclaration $left, SloDeclaration $right): string
    {
        $differences = DeclarationDiff::between($left->definition, $right->definition);
        $describe = static fn (bool $leftSide): string => $differences === []
            ? 'identical, declared twice'
            : implode(', ', array_map(static fn (FieldDifference $d): string => $d->field . ' ' . ($leftSide ? $d->left : $d->right), $differences));

        $message = \sprintf(
            'SLO %s is declared differently: %s (%s) vs %s (%s)',
            $name,
            $left->point->toString(),
            $describe(true),
            $right->point->toString(),
            $describe(false),
        );
        foreach ($differences as $difference) {
            $message .= \sprintf("\n  %s %s vs %s", $difference->field, $difference->left, $difference->right);
        }

        return $message;
    }

    /**
     * @param list<non-empty-string> $messages
     *
     * @return list<non-empty-string>
     */
    private function sorted(array $messages): array
    {
        $messages = array_values(array_unique($messages));
        sort($messages, SORT_STRING);

        return $messages;
    }
}
