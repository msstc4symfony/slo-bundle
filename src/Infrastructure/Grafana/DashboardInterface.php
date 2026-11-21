<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Grafana;

use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\DashboardContext;

/**
 * @internal
 */
interface DashboardInterface
{
    /**
     * @return non-empty-string
     */
    public function title(): string;

    /**
     * @return non-empty-string file name part: grafana/dashboards/slo-<slug>.json
     */
    public function slug(): string;

    /**
     * @return non-empty-list<Panel> in layout order
     */
    public function panels(DashboardContext $context): array;
}
