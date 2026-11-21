<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Presentation\Command;

use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\Action as GenerateDashboards;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\Input as DashboardsInput;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateRules\Action as GenerateRules;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateRules\Input as RulesInput;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Application\Generation\SyncFiles\Action as SyncFiles;
use Msstc4Symfony\SloBundle\Application\Generation\SyncFiles\Input as SyncInput;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'slo:generate', description: 'Generate Prometheus rules and Grafana dashboards from the SLO catalog; --check exits 1 when the files on disk are out of date')]
final class GenerateCommand extends Command
{
    /**
     * @param list<string> $components
     * @param positive-int|null $noEventsAlertHours
     * @param non-empty-string $datasourceUid
     * @param non-empty-string $folder
     * @param list<string> $ignoredRoutes
     */
    public function __construct(
        private readonly GenerateRules $rules,
        private readonly GenerateDashboards $dashboards,
        private readonly SyncFiles $sync,
        private readonly ?string $application,
        private readonly array $components,
        private readonly string $outputDir,
        private readonly bool $prometheusRules,
        private readonly bool $prometheusAlerts,
        private readonly ?int $noEventsAlertHours,
        private readonly string $datasourceUid,
        private readonly string $folder,
        private readonly array $ignoredRoutes,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output directory, overrides generation.output_dir')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Compare with the files on disk without writing')
        ;
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        if ($this->application === null || $this->application === '') {
            $errors->writeln('<error>Set msstc4symfony_slo.generation.selector.application to the "application" label of your metrics: slo:generate never reads it from the runtime environment.</error>');

            return Command::INVALID;
        }
        $root = $input->getOption('output') ?? $this->outputDir;
        $root = \is_string($root) ? rtrim($root, '/') : '';
        if ($root === '') {
            $errors->writeln('<error>The output directory must be a non-empty path other than "/".</error>');

            return Command::INVALID;
        }
        $check = $input->getOption('check') === true;

        $selector = new Selector($this->application, $this->components);
        $generated = ($this->rules)(new RulesInput(
            $selector,
            $this->prometheusRules,
            $this->prometheusAlerts,
            $this->noEventsAlertHours,
        ));
        foreach ($generated->warnings as $warning) {
            $errors->writeln('WARNING ' . OutputFormatter::escape($warning));
        }

        $dashboards = ($this->dashboards)(new DashboardsInput($selector, $this->datasourceUid, $this->folder, $this->ignoredRoutes));

        $result = ($this->sync)(new SyncInput($generated->files->merge($dashboards->files), $root, $check));

        if ($check) {
            if ($result->isUpToDate()) {
                $output->writeln('Generated files are up to date.');

                return Command::SUCCESS;
            }
            foreach (['stale  ' => $result->stale, 'missing' => $result->missing, 'extra  ' => $result->extra] as $state => $paths) {
                foreach ($paths as $path) {
                    $output->writeln(OutputFormatter::escape($state . ' ' . $path));
                }
            }
            $output->writeln(\sprintf('Generated files in %s are out of date, run slo:generate.', OutputFormatter::escape($root)));

            return Command::FAILURE;
        }

        foreach ([...$result->missing, ...$result->stale] as $path) {
            $output->writeln(OutputFormatter::escape('written ' . $path));
        }
        foreach ($result->extra as $path) {
            $output->writeln(OutputFormatter::escape('deleted ' . $path));
        }
        $output->writeln(\sprintf('%d file(s) written, %d deleted in %s', \count($result->missing) + \count($result->stale), \count($result->extra), OutputFormatter::escape($root)));

        return Command::SUCCESS;
    }
}
