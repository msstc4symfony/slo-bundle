<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Sloth;

use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Application\Port\SlothSpecBuilderInterface;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\PromQlBuilder;
use Msstc4Symfony\SloBundle\Infrastructure\Yaml\DeterministicYaml;
use Override;

/**
 * Sloth `prometheus/v1` spec: one SLO per objective, queries are the bundle's bad/total selectors over Sloth's
 * `{{.window}}` template. The SLO window is not part of the spec: Sloth takes it from its own period configuration.
 *
 * @internal
 */
final readonly class SlothSpecBuilder implements SlothSpecBuilderInterface
{
    private const WINDOW = '{{.window}}';

    public function __construct(
        private PromQlBuilder $promQl,
        private DeterministicYaml $yaml,
    ) {
    }

    #[Override]
    public function supports(BoundObjective $objective): bool
    {
        return true;
    }

    #[Override]
    public function spec(Selector $selector, array $objectives): string
    {
        $slos = [];
        foreach ($objectives as $objective) {
            $slos[] = $this->slo($selector, $objective);
        }

        return $this->yaml->dump([
            'version' => 'prometheus/v1',
            'service' => $selector->application,
            'labels' => ['application' => $selector->application],
            'slos' => $slos,
        ]);
    }

    /**
     * @return array<non-empty-string, mixed>
     */
    private function slo(Selector $selector, BoundObjective $objective): array
    {
        $name = $objective->definition->name()->value;
        $kind = $objective->objective->kind->value;
        $labels = ['application' => $selector->application, 'objective' => $kind, 'slo' => $name];

        return [
            'name' => $name . '-' . $kind,
            'objective' => $objective->objective->target->percent,
            'description' => \sprintf('SLO %s %s, target %s%%', $name, $kind, $objective->objective->target->percent),
            'labels' => $labels,
            'sli' => ['events' => [
                'error_query' => $this->promQl->badRate($selector, $objective, self::WINDOW),
                'total_query' => $this->promQl->eventsRate($selector, $objective, self::WINDOW),
            ]],
            'alerting' => [
                'name' => 'Slo' . $this->pascal($name) . ucfirst($kind),
                'labels' => $labels,
                'page_alert' => ['labels' => ['severity' => 'page']],
                'ticket_alert' => ['labels' => ['severity' => 'ticket']],
            ],
        ];
    }

    private function pascal(string $name): string
    {
        return implode('', array_map(ucfirst(...), explode('-', strtr($name, '._', '--'))));
    }
}
