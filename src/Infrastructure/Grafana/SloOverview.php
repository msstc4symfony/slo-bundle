<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Grafana;

use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\DashboardContext;
use Override;

/**
 * @internal
 */
final readonly class SloOverview implements DashboardInterface
{
    public function __construct(
        private PanelFactory $panels,
        private SloRows $sloRows,
    ) {
    }

    #[Override]
    public function title(): string
    {
        return 'SLO Overview';
    }

    #[Override]
    public function slug(): string
    {
        return 'slo-overview';
    }

    #[Override]
    public function panels(DashboardContext $context): array
    {
        $panels = $this->sloRows->panels($context->objectives);

        return $panels !== [] ? $panels : [$this->panels->markdown('SLO overview', 'No SLO is declared.', height: 4)];
    }
}
