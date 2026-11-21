<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards;

use Msstc4Symfony\SloBundle\Application\Binding\DurationBuckets;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Action as AnalyzeCoverage;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Input as CoverageInput;
use Msstc4Symfony\SloBundle\Application\Generation\BoundObjectiveCollector;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFileSet;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\GrafanaFileBuilderInterface;
use Msstc4Symfony\SloBundle\Application\Port\SeriesCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;

/**
 * @internal
 */
final readonly class Action
{
    public const DASHBOARDS_DIRECTORY = 'grafana/dashboards';

    public const PROVISIONING_DIRECTORY = 'grafana/provisioning/dashboards';

    public const PROVISIONING_FILE = self::PROVISIONING_DIRECTORY . '/slo.yaml';

    /**
     * @param list<string> $ignoredRoutes routes excluded from the coverage table (coverage.http_routes.ignored_routes)
     */
    public function __construct(
        private BoundObjectiveCollector $objectives,
        private AnalyzeCoverage $coverage,
        private GrafanaFileBuilderInterface $builder,
        private CatalogDiagnosticsInterface $diagnostics,
        private SeriesCatalogInterface $series,
        private array $ignoredRoutes = [],
    ) {
    }

    public function __invoke(Input $input): Output
    {
        $context = new DashboardContext(
            $input->selector,
            $this->objectives->collect(),
            ($this->coverage)(new CoverageInput($this->ignoredRoutes)),
            $this->hasLogicHistogram(),
        );

        return new Output(new GeneratedFileSet(
            [self::DASHBOARDS_DIRECTORY, self::PROVISIONING_DIRECTORY],
            [
                ...$this->builder->dashboards($context),
                new GeneratedFile(self::PROVISIONING_FILE, $this->builder->provisioning()),
            ],
        ));
    }

    private function hasLogicHistogram(): bool
    {
        return DurationBuckets::of($this->series, $this->diagnostics, PointType::LogicAction) !== null;
    }
}
