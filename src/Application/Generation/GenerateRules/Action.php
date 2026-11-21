<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\GenerateRules;

use Msstc4Symfony\SloBundle\Application\Generation\BoundObjectiveCollector;
use Msstc4Symfony\SloBundle\Application\Generation\BurnRatePolicy;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFile;
use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFileSet;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\RuleFileBuilderInterface;

final readonly class Action
{
    public const DIRECTORY = 'prometheus';

    public const RECORDING_FILE = 'prometheus/slo-recording.rules.yml';

    public const ALERTS_FILE = 'prometheus/slo-alerts.rules.yml';

    public function __construct(
        private BoundObjectiveCollector $objectives,
        private RuleFileBuilderInterface $builder,
        private BurnRatePolicy $burnRates,
        private CatalogDiagnosticsInterface $diagnostics,
    ) {
    }

    public function __invoke(Input $input): Output
    {
        $objectives = $this->objectives->collect();
        $warnings = self::configurationWarnings($input->recordingRules, $input->alertRules);
        foreach (array_keys($this->diagnostics->conflicts()) as $name) {
            $warnings[] = \sprintf('SLO %s is declared differently on several points and gets no rules, see slo:validate', $name);
        }

        $files = [];
        if ($input->recordingRules) {
            $files[] = new GeneratedFile(self::RECORDING_FILE, $this->builder->recordingRules($input->selector, $objectives));
        }
        if ($input->alertRules) {
            $files[] = new GeneratedFile(self::ALERTS_FILE, $this->builder->alertRules($input->selector, $objectives, $input->noEventsAlertHours));
            foreach ($objectives as $bound) {
                array_push($warnings, ...$this->burnRates->warnings(
                    $bound->definition->name()->value,
                    $bound->objective->kind->value,
                    $bound->definition->window(),
                    $bound->objective->target,
                ));
            }
        }

        return new Output(new GeneratedFileSet([self::DIRECTORY], $files), $warnings);
    }

    /**
     * @return list<non-empty-string>
     */
    public static function configurationWarnings(bool $recordingRules, bool $alertRules): array
    {
        return $alertRules && !$recordingRules
            ? ['generation.prometheus.alerts is enabled but generation.prometheus.rules is not: the alert rules read slo:* records that are not generated']
            : [];
    }
}
