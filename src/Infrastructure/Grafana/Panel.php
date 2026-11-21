<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Grafana;

use InvalidArgumentException;

/**
 * A panel without `id` and `gridPos`: DashboardBuilder assigns both while laying the dashboard out.
 *
 * One colour step of a threshold scale; the base step has no value.
 *
 * @phpstan-type ThresholdStep array{color: non-empty-string, value: float|null}
 * A range value mapping of a stat panel.
 * @phpstan-type ValueMapping array{type: 'range', options: array{from: int|float, to: int|float, result: array{color: non-empty-string, index: non-negative-int, text: string}}}
 * `fieldConfig.defaults` of a query panel.
 * @phpstan-type FieldDefaults array{
 *     unit: string,
 *     noValue?: string,
 *     color?: array{mode: 'thresholds'},
 *     thresholds?: array{mode: 'absolute', steps: list<ThresholdStep>},
 *     mappings?: list<ValueMapping>,
 *     min?: float,
 *     max?: float,
 * }
 * Panel `options`, whose keys depend on the panel type.
 * @phpstan-type PanelOptions array<non-empty-string, bool|string|array<array-key, mixed>>
 * One query of a panel.
 * @phpstan-type TargetJson array{datasource: array{type: 'prometheus', uid: non-empty-string}, expr: string, legendFormat: string, refId: non-empty-string}
 * Grafana panel JSON without `id` and `gridPos`.
 * @phpstan-type PanelJson array{
 *     type: 'timeseries'|'stat'|'gauge'|'text'|'row',
 *     title: string,
 *     datasource?: array{type: 'prometheus', uid: non-empty-string},
 *     fieldConfig?: array{defaults: FieldDefaults, overrides: list<never>},
 *     options?: PanelOptions,
 *     targets?: list<TargetJson>,
 *     collapsed?: bool,
 *     panels?: list<never>,
 * }
 *
 * @internal
 */
final readonly class Panel
{
    public const GRID_COLUMNS = 24;

    /**
     * @param PanelJson $json
     * @param int $width in grid columns, 1..GRID_COLUMNS (checked at runtime)
     * @param int $height in grid rows, at least 1 (checked at runtime)
     */
    public function __construct(
        public array $json,
        public int $width,
        public int $height,
    ) {
        if ($width < 1 || $width > self::GRID_COLUMNS || $height < 1) {
            throw new InvalidArgumentException(\sprintf('Panel size %dx%d is outside the %d column grid.', $width, $height, self::GRID_COLUMNS));
        }
    }
}
