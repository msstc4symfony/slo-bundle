<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Sloth;

use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Application\Generation\BurnRateSeverity;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Application\Port\SlothSpecBuilderInterface;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\PromQlBuilder;
use Msstc4Symfony\SloBundle\Infrastructure\Yaml\DeterministicYaml;
use Override;

/**
 * Sloth `prometheus/v1` spec: one SLO per objective, queries are the bundle's bad/total selectors over Sloth's
 * `{{.window}}` template. The SLO window is not part of the spec: Sloth takes it from its own period configuration.
 *
 * Shapes: SlothLabels are the labels of a Sloth SLO and its alerts; SlothSloShape is one entry of the `slos` list;
 * SlothSpecShape is the `prometheus/v1` spec document.
 *
 * @phpstan-type SlothLabels array{application: non-empty-string, objective: non-empty-string, slo: non-empty-string}
 * @phpstan-type SlothSloShape array{
 *     name: non-empty-string,
 *     objective: float,
 *     description: non-empty-string,
 *     labels: SlothLabels,
 *     sli: array{events: array{error_query: string, total_query: string}},
 *     alerting: array{
 *         name: non-empty-string,
 *         labels: SlothLabels,
 *         page_alert: array{labels: array{severity: 'page'}},
 *         ticket_alert: array{labels: array{severity: 'ticket'}},
 *     },
 * }
 * @phpstan-type SlothSpecShape array{version: 'prometheus/v1', service: non-empty-string, labels: array{application: non-empty-string}, slos: list<SlothSloShape>}
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

        return $this->yaml->dump($this->document($selector, $slos));
    }

    /**
     * @param list<SlothSloShape> $slos
     *
     * @return SlothSpecShape
     */
    private function document(Selector $selector, array $slos): array
    {
        return [
            'version' => 'prometheus/v1',
            'service' => $selector->application,
            'labels' => ['application' => $selector->application],
            'slos' => $slos,
        ];
    }

    /**
     * @return SlothSloShape
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
                'page_alert' => ['labels' => ['severity' => BurnRateSeverity::Page->value]],
                'ticket_alert' => ['labels' => ['severity' => BurnRateSeverity::Ticket->value]],
            ],
        ];
    }

    private function pascal(string $name): string
    {
        return implode('', array_map(ucfirst(...), explode('-', strtr($name, '._', '--'))));
    }
}
