<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Grafana;

use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\Action;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\DashboardContext;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;
use Msstc4Symfony\SloBundle\Application\Port\GrafanaFileBuilderInterface;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\PromQlBuilder;
use Msstc4Symfony\SloBundle\Infrastructure\Yaml\DeterministicYaml;
use Override;

final readonly class GrafanaFileBuilder implements GrafanaFileBuilderInterface
{
    /**
     * @param list<DashboardInterface> $dashboards
     */
    public function __construct(
        private array $dashboards,
        private DashboardBuilder $builder,
        private DeterministicJson $json,
        private DeterministicYaml $yaml,
    ) {
    }

    public static function standard(): self
    {
        $panels = new PanelFactory();
        $rows = new SloRows($panels, new PromQlBuilder());

        return new self(
            [
                new ApplicationOverview($panels),
                new HttpRoutes($panels, $rows),
                new Messenger($panels, $rows),
                new BusinessOperations($panels, $rows),
                new SloOverview($panels, $rows),
                new MonitoringCoverage($panels),
            ],
            new DashboardBuilder(),
            new DeterministicJson(),
            new DeterministicYaml(),
        );
    }

    #[Override]
    public function dashboards(DashboardContext $context): array
    {
        $files = [];
        foreach ($this->dashboards as $dashboard) {
            $files[] = new GeneratedFile(
                \sprintf('%s/slo-%s.json', Action::DASHBOARDS_DIRECTORY, $dashboard->slug()),
                $this->json->encode($this->builder->build($dashboard, $context)),
            );
        }

        return $files;
    }

    #[Override]
    public function provisioning(string $folder, string $dashboardsPath): string
    {
        return $this->yaml->dump([
            'apiVersion' => 1,
            'providers' => [[
                'allowUiUpdates' => false,
                'disableDeletion' => false,
                'folder' => $folder,
                'name' => 'slo',
                'options' => ['path' => $dashboardsPath],
                'type' => 'file',
            ]],
        ]);
    }
}
