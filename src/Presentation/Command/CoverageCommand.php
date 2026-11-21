<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Presentation\Command;

use Closure;
use JsonException;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Action;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\CoverageKind;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\CoverageStatus;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Input;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\KindCoverage;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Output;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\PointCoverage;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @internal the command name, options, output and exit codes are the public contract, not this class
 */
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
     * @return array<value-of<CoverageKind>, float>|null null when --min is not a number from 0 to 100
     */
    private function required(mixed $min): ?array
    {
        if ($min === null) {
            return [
                CoverageKind::HttpRoute->value => (float) $this->httpRequiredPercent,
                CoverageKind::MessengerHandler->value => (float) $this->messengerRequiredPercent,
                CoverageKind::LogicAction->value => (float) $this->logicRequiredPercent,
            ];
        }
        if (!\is_string($min) || !is_numeric($min)) {
            return null;
        }
        $percent = (float) $min;
        if (!is_finite($percent) || $percent < 0 || $percent > 100) {
            return null;
        }

        return array_fill_keys(array_map(static fn (CoverageKind $kind): string => $kind->value, CoverageKind::cases()), $percent);
    }

    /**
     * @param array<value-of<CoverageKind>, float> $required
     * @param list<CoverageKind> $below
     */
    private function text(OutputInterface $output, Output $report, array $required, array $below): void
    {
        foreach ($report->points as $point) {
            if ($point->status === CoverageStatus::Monitored) {
                continue;
            }
            $output->writeln(OutputFormatter::escape(\sprintf('%-11s %-17s %s - %s', $point->status->value, $point->kind->value, $point->point, $point->reason ?? '')));
        }

        foreach ($report->kinds() as $coverage) {
            $kind = $coverage->kind->value;
            $line = \sprintf('%-17s SLO %s%%, measured by universal metrics %s%%', $kind, number_format($coverage->sloPercent, 2, '.', ''), number_format($coverage->measuredPercent, 2, '.', ''));
            if (isset($required[$kind])) {
                $line .= \sprintf(', required %s%%', rtrim(rtrim(number_format($required[$kind], 2, '.', ''), '0'), '.'));
                if (\in_array($coverage->kind, $below, true)) {
                    // The percent is rounded for display, the gate compares the exact fraction.
                    $line .= \sprintf(' BELOW (%d of %d eligible points have an SLO)', $coverage->monitored, $coverage->eligible);
                }
            }
            $output->writeln($line);
        }

        if ($report->invalid > 0) {
            $output->writeln(\sprintf('%d invalid point(s)', $report->invalid));
        }
    }

    /**
     * @param array<value-of<CoverageKind>, float> $required
     *
     * @throws JsonException
     */
    private function json(Output $report, array $required, bool $passed): string
    {
        $document = [
            'invalid' => $report->invalid,
            'measured_percent' => $this->percentByKind($report, static fn (KindCoverage $coverage): float => $coverage->measuredPercent),
            'passed' => $passed,
            'points' => array_map(static fn (PointCoverage $point): array => [
                'kind' => $point->kind->value,
                'measured_by_universal_metric' => $point->measuredByUniversalMetric,
                'point' => $point->point,
                'reason' => $point->reason,
                'slos' => $point->slos,
                'status' => $point->status->value,
            ], $report->points),
            'required_percent' => $required,
            'slo_percent' => $this->percentByKind($report, static fn (KindCoverage $coverage): float => $coverage->sloPercent),
        ];
        ksort($document['measured_percent']);
        ksort($document['required_percent']);
        ksort($document['slo_percent']);

        return json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * @param Closure(KindCoverage): float $percent
     *
     * @return array<value-of<CoverageKind>, float>
     */
    private function percentByKind(Output $report, Closure $percent): array
    {
        $byKind = [];
        foreach ($report->kinds() as $coverage) {
            $byKind[$coverage->kind->value] = $percent($coverage);
        }

        return $byKind;
    }
}
