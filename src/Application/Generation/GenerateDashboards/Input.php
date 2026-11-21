<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards;

use Msstc4Symfony\SloBundle\Application\Generation\Selector;

final readonly class Input
{
    public const DEFAULT_DASHBOARDS_PATH = '/var/lib/grafana/dashboards';

    /**
     * @param non-empty-string $datasourceUid
     * @param non-empty-string $folder
     * @param list<string> $ignoredRoutes routes excluded from the coverage table (coverage.http_routes.ignored_routes)
     * @param non-empty-string $dashboardsPath where Grafana finds the dashboard files, the provisioning `options.path`
     */
    public function __construct(
        public Selector $selector,
        public string $datasourceUid = 'prometheus',
        public string $folder = 'SLO',
        public array $ignoredRoutes = [],
        public string $dashboardsPath = self::DEFAULT_DASHBOARDS_PATH,
    ) {
    }
}
