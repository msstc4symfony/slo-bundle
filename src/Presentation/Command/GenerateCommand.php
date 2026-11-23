<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Presentation\Command;

use Msstc4Symfony\SloBundle\Application\Generation\Generate\Action as Generate;
use Msstc4Symfony\SloBundle\Application\Generation\Generate\Exception\InvalidGenerationSettings;
use Msstc4Symfony\SloBundle\Application\Generation\Generate\Input as GenerateInput;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal the command name, options, output and exit codes are the public contract, not this class
 */
#[AsCommand(name: 'slo:generate', description: 'Generate Prometheus rules, Grafana dashboards, optional Sloth specs and Alertmanager routing from the SLO catalog; --check exits 1 when the files on disk are out of date')]
final class GenerateCommand extends Command
{
    public function __construct(
        private readonly Generate $generate,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Output directory, overrides generation.output_dir')
            ->addOption('page-receiver', null, InputOption::VALUE_REQUIRED, 'Alertmanager receiver for severity=page, overrides generation.alertmanager.receivers.page')
            ->addOption('ticket-receiver', null, InputOption::VALUE_REQUIRED, 'Alertmanager receiver for severity=ticket, overrides generation.alertmanager.receivers.ticket')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Compare with the files on disk without writing')
        ;
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $check = $input->getOption('check') === true;
        $directory = $input->getOption('output');
        $page = $input->getOption('page-receiver');
        $ticket = $input->getOption('ticket-receiver');

        try {
            $generated = ($this->generate)(new GenerateInput(\is_string($directory) ? $directory : null, $check, \is_string($page) ? $page : null, \is_string($ticket) ? $ticket : null));
        } catch (InvalidGenerationSettings $e) {
            $errors->writeln('<error>' . OutputFormatter::escape($e->getMessage()) . '</error>');

            return Command::INVALID;
        }
        foreach ($generated->warnings as $warning) {
            $errors->writeln('WARNING ' . OutputFormatter::escape($warning));
        }
        $result = $generated->files;
        $root = $generated->root;

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
