<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards;

use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Output as Coverage;
use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;

/**
 * Everything a dashboard is rendered from; all of it is known at compile time.
 */
final readonly class DashboardContext
{
    /**
     * @param non-empty-string $datasourceUid default of the `datasource` variable
     * @param list<BoundObjective> $objectives
     * @param bool $logicHistogram metrics-bundle declares the logic action duration histogram
     */
    public function __construct(
        public Selector $selector,
        public string $datasourceUid,
        public array $objectives,
        public Coverage $coverage,
        public bool $logicHistogram,
    ) {
    }
}
