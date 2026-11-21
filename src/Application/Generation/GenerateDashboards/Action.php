<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards;

use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Action as AnalyzeCoverage;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Input as CoverageInput;
use Msstc4Symfony\SloBundle\Application\Generation\BoundObjectiveCollector;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFileSet;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\GrafanaFileBuilderInterface;

/**
 * @internal
 */
final readonly class Action
{
    public const DASHBOARDS_DIRECTORY = 'grafana/dashboards';

    public const PROVISIONING_DIRECTORY = 'grafana/provisioning/dashboards';

    public const PROVISIONING_FILE = self::PROVISIONING_DIRECTORY . '/slo.yaml';

    private const LOGIC_HISTOGRAM = 'logic_action_duration_histogram_seconds';

    public function __construct(
        private BoundObjectiveCollector $objectives,
        private AnalyzeCoverage $coverage,
        private GrafanaFileBuilderInterface $builder,
        private CatalogDiagnosticsInterface $diagnostics,
    ) {
    }

    public function __invoke(Input $input): Output
    {
        $context = new DashboardContext(
            $input->selector,
            $input->datasourceUid,
            $this->objectives->collect(),
            ($this->coverage)(new CoverageInput($input->ignoredRoutes)),
            isset($this->diagnostics->extras()->metricBuckets[self::LOGIC_HISTOGRAM]),
        );

        return new Output(new GeneratedFileSet(
            [self::DASHBOARDS_DIRECTORY, self::PROVISIONING_DIRECTORY],
            [
                ...$this->builder->dashboards($context),
                new GeneratedFile(self::PROVISIONING_FILE, $this->builder->provisioning($input->folder, $input->dashboardsPath)),
            ],
        ));
    }
}
