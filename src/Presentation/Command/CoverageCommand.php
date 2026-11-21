<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Presentation\Command;

use JsonException;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Action;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Input;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Output;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\PointCoverage;
use Msstc4Symfony\SloBundle\Domain\Enum\CoverageStatus;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'slo:coverage', description: 'Report which routes, handlers and actions are covered by an SLO; exits 1 below the required percent')]
final class CoverageCommand extends Command
{
    private const FORMATS = ['text', 'json'];

    /**
     * @param list<string> $ignoredRoutes
     * @param int<0, 100> $httpRequiredPercent
     * @param int<0, 100> $messengerRequiredPercent
     * @param int<0, 100> $logicRequiredPercent
     */
    public function __construct(
        private readonly Action $action,
        private readonly array $ignoredRoutes,
        private readonly int $httpRequiredPercent,
        private readonly int $messengerRequiredPercent,
        private readonly int $logicRequiredPercent,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: text or json', 'text')
            ->addOption('min', null, InputOption::VALUE_REQUIRED, 'Required SLO coverage percent (0-100) for every kind, overrides coverage.*.required_percent')
        ;
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $format = $input->getOption('format');
        if (!\is_string($format) || !\in_array($format, self::FORMATS, true)) {
            $output->writeln('<error>Unknown format, expected text or json.</error>');

            return Command::INVALID;
        }

        $min = $input->getOption('min');
        $required = $this->required($min);
        if ($required === null) {
            $output->writeln('<error>Invalid --min, expected a number from 0 to 100.</error>');

            return Command::INVALID;
        }

        $report = ($this->action)(new Input($this->ignoredRoutes));
        $below = $report->belowThreshold($required);
        $passed = $below === [] && $report->invalid === 0;

        if ($format === 'json') {
            try {
                $output->writeln($this->json($report, $required, $passed), OutputInterface::OUTPUT_RAW);
            } catch (JsonException $exception) {
                $output->writeln('<error>' . OutputFormatter::escape($exception->getMessage()) . '</error>');

                return Command::FAILURE;
            }
        } else {
            $this->text($output, $report, $required, $below);
        }

        return $passed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * @return array<PointCoverage::KIND_*, float>|null null when --min is not a number from 0 to 100
     */
    private function required(mixed $min): ?array
    {
        if ($min === null) {
            return [
                PointCoverage::KIND_HTTP_ROUTE => (float) $this->httpRequiredPercent,
                PointCoverage::KIND_MESSENGER_HANDLER => (float) $this->messengerRequiredPercent,
                PointCoverage::KIND_LOGIC_ACTION => (float) $this->logicRequiredPercent,
            ];
        }
        if (!\is_string($min) || !is_numeric($min)) {
            return null;
        }
        $percent = (float) $min;
        if (!is_finite($percent) || $percent < 0 || $percent > 100) {
            return null;
        }

        return [
            PointCoverage::KIND_HTTP_ROUTE => $percent,
            PointCoverage::KIND_MESSENGER_HANDLER => $percent,
            PointCoverage::KIND_LOGIC_ACTION => $percent,
            PointCoverage::KIND_OPERATION => $percent,
        ];
    }

    /**
     * @param array<PointCoverage::KIND_*, float> $required
     * @param list<PointCoverage::KIND_*> $below
     */
    private function text(OutputInterface $output, Output $report, array $required, array $below): void
    {
        foreach ($report->points as $point) {
            if ($point->status === CoverageStatus::Monitored) {
                continue;
            }
            $output->writeln(OutputFormatter::escape(\sprintf('%-11s %-17s %s - %s', $point->status->value, $point->kind, $point->point, $point->reason ?? '')));
        }

        foreach (PointCoverage::KINDS as $kind) {
            $line = \sprintf('%-17s SLO %s%%, measured by universal metrics %s%%', $kind, number_format($report->sloPercent[$kind], 2, '.', ''), number_format($report->measuredPercent[$kind], 2, '.', ''));
            if (isset($required[$kind])) {
                $line .= \sprintf(', required %s%%%s', rtrim(rtrim(number_format($required[$kind], 2, '.', ''), '0'), '.'), \in_array($kind, $below, true) ? ' BELOW' : '');
            }
            $output->writeln($line);
        }

        if ($report->invalid > 0) {
            $output->writeln(\sprintf('%d invalid point(s)', $report->invalid));
        }
    }

    /**
     * @param array<PointCoverage::KIND_*, float> $required
     *
     * @throws JsonException
     */
    private function json(Output $report, array $required, bool $passed): string
    {
        $document = [
            'invalid' => $report->invalid,
            'measured_percent' => $report->measuredPercent,
            'passed' => $passed,
            'points' => array_map(static fn (PointCoverage $point): array => [
                'kind' => $point->kind,
                'measured_by_universal_metric' => $point->measuredByUniversalMetric,
                'point' => $point->point,
                'reason' => $point->reason,
                'slos' => $point->slos,
                'status' => $point->status->value,
            ], $report->points),
            'required_percent' => $required,
            'slo_percent' => $report->sloPercent,
        ];
        ksort($document['measured_percent']);
        ksort($document['required_percent']);
        ksort($document['slo_percent']);

        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
