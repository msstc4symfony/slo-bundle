<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Grafana;

use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\DashboardContext;
use Override;

/**
 * The catalog state at generation time as a Markdown table: static rows, no time series, portable between Grafana setups.
 *
 * @internal
 */
final readonly class MonitoringCoverage implements DashboardInterface
{
    private const CELL_ESCAPES = [
        '|' => '\\|',
        '[' => '\\[',
        ']' => '\\]',
        '`' => '\\`',
        '&' => '&amp;',
        '<' => '&lt;',
        '>' => '&gt;',
        '$' => '&#36;',
        "\r" => ' ',
        "\n" => ' ',
    ];

    public function __construct(private PanelFactory $panels)
    {
    }

    #[Override]
    public function title(): string
    {
        return 'Monitoring Coverage';
    }

    #[Override]
    public function slug(): string
    {
        return 'monitoring-coverage';
    }

    #[Override]
    public function panels(DashboardContext $context): array
    {
        $coverage = $context->coverage;
        $summary = ['| Kind | SLO coverage | Measured by a metric |', '| --- | --- | --- |'];
        foreach ($coverage->kinds() as $kind) {
            $summary[] = \sprintf('| %s | %s%% | %s%% |', $kind->kind->value, $this->number($kind->sloPercent), $this->number($kind->measuredPercent));
        }

        $rows = ['| Point | Kind | Status | SLOs | Note |', '| --- | --- | --- | --- | --- |'];
        foreach ($coverage->points as $point) {
            $rows[] = \sprintf(
                '| %s | %s | %s | %s | %s |',
                $this->cell($point->point),
                $point->kind->value,
                $point->status->value,
                $point->slos === [] ? '-' : $this->cell(implode(', ', $point->slos)),
                $point->reason === null ? '-' : $this->cell($point->reason),
            );
        }

        return [
            $this->panels->markdown('Coverage by kind', implode("\n", $summary) . "\n\nGenerated from the compiled SLO catalog; it changes only when slo:generate is run.\n", height: 8),
            $this->panels->markdown('Points', implode("\n", $rows) . "\n", height: max(8, 3 + \count($coverage->points))),
        ];
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(\sprintf('%.2F', $value), '0'), '.');
    }

    /**
     * Reasons and ids are free text: HTML and `$`-interpolation become entities, Markdown punctuation is escaped. A
     * backslash is doubled only before ASCII punctuation, where Markdown would read it as an escape (class names keep theirs).
     */
    private function cell(string $value): string
    {
        $value = preg_replace('/\\\\(?=[\x21-\x2F\x3A-\x40\x5B-\x60\x7B-\x7E])/', '\\\\\\\\', $value) ?? $value;

        return strtr($value, self::CELL_ESCAPES);
    }
}
