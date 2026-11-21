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

/**
 * @internal
 */
final readonly class GrafanaFileBuilder implements GrafanaFileBuilderInterface
{
    /** Where the generated dashboards are mounted in the Grafana container, the provisioning `options.path`. */
    public const DASHBOARDS_PATH = '/var/lib/grafana/dashboards';

    /**
     * @param list<DashboardInterface> $dashboards
     * @param non-empty-string $folder Grafana folder the dashboards are placed into
     */
    public function __construct(
        private array $dashboards,
        private DashboardBuilder $builder,
        private DeterministicJson $json,
        private DeterministicYaml $yaml,
        private string $folder = 'SLO',
    ) {
    }

    /**
     * @param non-empty-string $datasourceUid default of the dashboards' `datasource` variable
     * @param non-empty-string $folder
     */
    public static function standard(string $datasourceUid = 'prometheus', string $folder = 'SLO'): self
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
            new DashboardBuilder($datasourceUid),
            new DeterministicJson(),
            new DeterministicYaml(),
            $folder,
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
    public function provisioning(): string
    {
        return $this->yaml->dump([
            'apiVersion' => 1,
            'providers' => [[
                'allowUiUpdates' => false,
                'disableDeletion' => false,
                'folder' => $this->folder,
                'name' => 'slo',
                'options' => ['path' => self::DASHBOARDS_PATH],
                'type' => 'file',
            ]],
        ]);
    }
}
