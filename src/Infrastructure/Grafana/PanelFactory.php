<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Grafana;

/**
 * Panel JSON in the shapes Grafana 13 writes for schemaVersion 41. Every query panel shows "No data" for an empty series.
 *
 * @phpstan-import-type ThresholdStep from Panel
 * @phpstan-import-type ValueMapping from Panel
 * @phpstan-import-type FieldDefaults from Panel
 * @phpstan-import-type PanelOptions from Panel
 *
 * @internal
 */
final readonly class PanelFactory
{
    public const DATASOURCE = ['type' => 'prometheus', 'uid' => '${datasource}'];

    private const NO_VALUE = 'No data';

    private const REDUCE = ['calcs' => ['lastNotNull'], 'fields' => '', 'values' => false];

    /**
     * @param non-empty-list<Query> $queries
     * @param int<1, 24> $width
     * @param positive-int $height
     */
    public function timeseries(string $title, array $queries, string $unit, int $width = 12, int $height = 8): Panel
    {
        return $this->query('timeseries', $title, $queries, ['unit' => $unit], [
            'legend' => ['calcs' => [], 'displayMode' => 'list', 'placement' => 'bottom', 'showLegend' => true],
            'tooltip' => ['mode' => 'multi', 'sort' => 'none'],
        ], $width, $height);
    }

    /**
     * @param list<ThresholdStep> $thresholds ascending; the first is the base
     * @param list<ValueMapping> $mappings
     * @param int<1, 24> $width
     * @param positive-int $height
     */
    public function stat(string $title, Query $query, string $unit, array $thresholds = [], array $mappings = [], int $width = 4, int $height = 6): Panel
    {
        return $this->query('stat', $title, [$query], $this->defaults($unit, $thresholds, $mappings), [
            'colorMode' => 'value',
            'graphMode' => 'none',
            'reduceOptions' => self::REDUCE,
            'textMode' => 'auto',
        ], $width, $height);
    }

    /**
     * @param list<ThresholdStep> $thresholds ascending; the first is the base
     * @param int<1, 24> $width
     * @param positive-int $height
     */
    public function gauge(string $title, Query $query, string $unit, float $min, float $max, array $thresholds, int $width = 4, int $height = 6): Panel
    {
        return $this->query('gauge', $title, [$query], [...$this->defaults($unit, $thresholds, []), 'max' => $max, 'min' => $min], [
            'reduceOptions' => self::REDUCE,
            'showThresholdLabels' => false,
            'showThresholdMarkers' => true,
        ], $width, $height);
    }

    /**
     * @param int<1, 24> $width
     * @param positive-int $height
     */
    public function markdown(string $title, string $content, int $width = 24, int $height = 12): Panel
    {
        return new Panel([
            'options' => ['content' => $content, 'mode' => 'markdown'],
            'title' => $title,
            'type' => 'text',
        ], $width, $height);
    }

    public function row(string $title): Panel
    {
        return new Panel(['collapsed' => false, 'panels' => [], 'title' => $title, 'type' => 'row'], Panel::GRID_COLUMNS, 1);
    }

    /**
     * @param list<ThresholdStep> $thresholds
     * @param list<ValueMapping> $mappings
     *
     * @return FieldDefaults
     */
    private function defaults(string $unit, array $thresholds, array $mappings): array
    {
        $defaults = ['unit' => $unit];
        if ($thresholds !== []) {
            $defaults['color'] = ['mode' => 'thresholds'];
            $defaults['thresholds'] = ['mode' => 'absolute', 'steps' => $thresholds];
        }
        if ($mappings !== []) {
            $defaults['mappings'] = $mappings;
        }

        return $defaults;
    }

    /**
     * @param 'timeseries'|'stat'|'gauge' $type
     * @param non-empty-list<Query> $queries
     * @param FieldDefaults $defaults
     * @param PanelOptions $options
     * @param int<1, 24> $width
     * @param positive-int $height
     */
    private function query(string $type, string $title, array $queries, array $defaults, array $options, int $width, int $height): Panel
    {
        $targets = [];
        foreach (array_values($queries) as $index => $query) {
            $target = [
                'datasource' => self::DATASOURCE,
                'expr' => $query->expr,
                'legendFormat' => $query->legend,
                'refId' => \chr(ord('A') + $index),
            ];
            if ($query->instant) {
                $target['instant'] = true;
                $target['range'] = false;
            }
            $targets[] = $target;
        }

        return new Panel([
            'datasource' => self::DATASOURCE,
            'fieldConfig' => ['defaults' => [...$defaults, 'noValue' => self::NO_VALUE], 'overrides' => []],
            'options' => $options,
            'targets' => $targets,
            'title' => $title,
            'type' => $type,
        ], $width, $height);
    }
}
