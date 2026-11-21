<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\DashboardContext;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;

/**
 * Renders the Grafana dashboards and the file provisioning config.
 */
interface GrafanaFileBuilderInterface
{
    /**
     * @return list<GeneratedFile> one JSON file per dashboard
     */
    public function dashboards(DashboardContext $context): array;

    /**
     * @param non-empty-string $folder Grafana folder the dashboards are placed into
     * @param non-empty-string $dashboardsPath directory of the dashboard files as seen by Grafana
     */
    public function provisioning(string $folder, string $dashboardsPath): string;
}
