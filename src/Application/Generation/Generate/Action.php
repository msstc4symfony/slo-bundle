<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\Generate;

use Msstc4Symfony\SloBundle\Application\Generation\Generate\Exception\InvalidGenerationSettings;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\Action as GenerateDashboards;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\Input as DashboardsInput;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateRules\Action as GenerateRules;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateRules\Input as RulesInput;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateSloth\Action as GenerateSloth;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateSloth\Input as SlothInput;
use Msstc4Symfony\SloBundle\Application\Generation\SyncFiles\Action as SyncFiles;
use Msstc4Symfony\SloBundle\Application\Generation\SyncFiles\Input as SyncInput;

/**
 * Generates rules, dashboards and optional Sloth specs and syncs them with the output directory.
 *
 * @internal
 */
final readonly class Action
{
    public function __construct(
        private GenerateRules $rules,
        private GenerateDashboards $dashboards,
        private GenerateSloth $sloth,
        private SyncFiles $sync,
        private GenerationConfig $config,
    ) {
    }

    /**
     * @throws InvalidGenerationSettings
     */
    public function __invoke(Input $input): Output
    {
        if (!$this->config->alertmanager && ($input->pageReceiver !== null || $input->ticketReceiver !== null)) {
            throw InvalidGenerationSettings::receiverOverrideWithoutAlertmanager();
        }
        if ($this->config->alertmanager) {
            $this->config->alertmanagerReceivers($input->pageReceiver, $input->ticketReceiver);
        }

        $selector = $this->config->selector();
        $root = rtrim($input->outputDir ?? $this->config->outputDir, '/');
        if ($root === '') {
            throw InvalidGenerationSettings::invalidOutputDirectory();
        }

        $rules = ($this->rules)(new RulesInput($selector, $this->config->prometheusRules, $this->config->prometheusAlerts, $this->config->noEventsAlertHours));
        $dashboards = ($this->dashboards)(new DashboardsInput($selector));
        $sloth = ($this->sloth)(new SlothInput($selector, $this->config->sloth));

        $files = ($this->sync)(new SyncInput($rules->files->merge($dashboards->files)->merge($sloth->files), $root, $input->check));

        return new Output($root, $files, [...$rules->warnings, ...$sloth->warnings]);
    }
}
