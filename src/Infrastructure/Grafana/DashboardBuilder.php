<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Grafana;

use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\DashboardContext;

/**
 * Wraps the panels of one dashboard: stable uid, panel ids 1..n and a fixed left-to-right grid layout, the four template
 * variables and fixed time defaults. Nothing here reads the clock or the environment.
 *
 * Shapes: TemplateVariable is a dashboard template variable, its keys depend on its type; DashboardJson is the
 * dashboard JSON written for file provisioning.
 *
 * @phpstan-type TemplateVariable array<non-empty-string, bool|int|string|null|array<array-key, mixed>>
 * @phpstan-type DashboardJson array{
 *     annotations: array{list: list<never>},
 *     editable: false,
 *     graphTooltip: 1,
 *     id: null,
 *     links: list<never>,
 *     panels: list<array<non-empty-string, mixed>>,
 *     refresh: non-empty-string,
 *     schemaVersion: int,
 *     tags: list<non-empty-string>,
 *     templating: array{list: list<TemplateVariable>},
 *     time: array{from: non-empty-string, to: non-empty-string},
 *     timezone: 'utc',
 *     title: non-empty-string,
 *     uid: non-empty-string,
 * }
 *
 * @internal
 */
final readonly class DashboardBuilder
{
    public const SCHEMA_VERSION = 41;

    /**
     * @param non-empty-string $datasourceUid default of the `datasource` variable
     */
    public function __construct(private string $datasourceUid = 'prometheus')
    {
    }

    /**
     * @return DashboardJson
     */
    public function build(DashboardInterface $dashboard, DashboardContext $context): array
    {
        $application = $context->selector->application;

        return [
            'annotations' => ['list' => []],
            'editable' => false,
            'graphTooltip' => 1,
            'id' => null,
            'links' => [],
            'panels' => $this->layout($dashboard->panels($context)),
            'refresh' => '1m',
            'schemaVersion' => self::SCHEMA_VERSION,
            'tags' => ['slo'],
            'templating' => ['list' => $this->variables($context)],
            'time' => ['from' => 'now-24h', 'to' => 'now'],
            'timezone' => 'utc',
            'title' => \sprintf('SLO: %s (%s)', $dashboard->title(), $application),
            'uid' => self::uid($dashboard->title(), $application),
        ];
    }

    /**
     * @return non-empty-string
     */
    public static function uid(string $name, string $application): string
    {
        return 'slo-' . substr(hash('sha256', $name . $application), 0, 12);
    }

    /**
     * @param list<Panel> $panels
     *
     * @return list<array<non-empty-string, mixed>> Panel::$json plus `gridPos` and `id` 1..n
     */
    private function layout(array $panels): array
    {
        $laidOut = [];
        $x = 0;
        $y = 0;
        $rowHeight = 0;
        foreach ($panels as $index => $panel) {
            if ($x > 0 && $x + $panel->width > Panel::GRID_COLUMNS) {
                $y += $rowHeight;
                $x = 0;
                $rowHeight = 0;
            }
            $laidOut[] = [
                ...$panel->json,
                'gridPos' => ['h' => $panel->height, 'w' => $panel->width, 'x' => $x, 'y' => $y],
                'id' => $index + 1,
            ];
            $x += $panel->width;
            $rowHeight = max($rowHeight, $panel->height);
            if ($x >= Panel::GRID_COLUMNS) {
                $y += $rowHeight;
                $x = 0;
                $rowHeight = 0;
            }
        }

        return $laidOut;
    }

    /**
     * @return list<TemplateVariable>
     */
    private function variables(DashboardContext $context): array
    {
        $application = $context->selector->application;
        $components = $context->selector->components;
        $component = $components === []
            ? ['selected' => true, 'text' => ['All'], 'value' => ['$__all']]
            : ['selected' => true, 'text' => $components, 'value' => $components];

        return [
            [
                'current' => ['text' => $this->datasourceUid, 'value' => $this->datasourceUid],
                'hide' => 0,
                'includeAll' => false,
                'label' => 'Datasource',
                'multi' => false,
                'name' => 'datasource',
                'options' => [],
                'query' => 'prometheus',
                'refresh' => 1,
                'regex' => '',
                'type' => 'datasource',
            ],
            [
                'current' => ['text' => $application, 'value' => $application],
                'hide' => 2,
                'label' => 'Application',
                'name' => 'application',
                'options' => [['selected' => true, 'text' => $application, 'value' => $application]],
                'query' => $application,
                'type' => 'constant',
            ],
            [
                'allValue' => '.*',
                'current' => $component,
                'datasource' => PanelFactory::DATASOURCE,
                'definition' => 'label_values(symfony_http_request{application="$application"}, component)',
                'hide' => 0,
                'includeAll' => true,
                'label' => 'Component',
                'multi' => true,
                'name' => 'component',
                'options' => [],
                'query' => 'label_values(symfony_http_request{application="$application"}, component)',
                'refresh' => 2,
                'regex' => '',
                'sort' => 1,
                'type' => 'query',
            ],
            [
                'current' => ['text' => '', 'value' => ''],
                'hide' => 0,
                'label' => 'Environment',
                'name' => 'environment',
                'options' => [['selected' => true, 'text' => '', 'value' => '']],
                'query' => '',
                'type' => 'custom',
            ],
        ];
    }
}
