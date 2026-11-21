<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\DashboardContext;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;

/**
 * Renders the Grafana dashboards and the file provisioning config.
 *
 * @internal
 */
interface GrafanaFileBuilderInterface
{
    /**
     * @return list<GeneratedFile> one JSON file per dashboard
     */
    public function dashboards(DashboardContext $context): array;

    /**
     * The file-provisioning config; the Grafana folder and the dashboards path are settings of the adapter.
     */
    public function provisioning(): string;
}
