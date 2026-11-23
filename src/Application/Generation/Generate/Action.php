<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\Generate;

use Msstc4Symfony\SloBundle\Application\Generation\Generate\Exception\InvalidGenerationSettings;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateAlertmanager\Action as GenerateAlertmanager;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateAlertmanager\Input as AlertmanagerInput;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\Action as GenerateDashboards;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards\Input as DashboardsInput;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateRules\Action as GenerateRules;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateRules\Input as RulesInput;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateSloth\Action as GenerateSloth;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateSloth\Input as SlothInput;
use Msstc4Symfony\SloBundle\Application\Generation\SyncFiles\Action as SyncFiles;
use Msstc4Symfony\SloBundle\Application\Generation\SyncFiles\Input as SyncInput;

/**
 * Generates rules, dashboards, optional Sloth specs and Alertmanager routing and syncs them with the output directory.
 *
 * @internal
 */
final readonly class Action
{
    public function __construct(
        private GenerateRules $rules,
        private GenerateDashboards $dashboards,
        private GenerateSloth $sloth,
        private GenerateAlertmanager $alertmanager,
        private SyncFiles $sync,
        private GenerationConfig $config,
    ) {
    }

    /**
     * @throws InvalidGenerationSettings
     */
    public function __invoke(Input $input): Output
    {
        $receivers = null;
        if ($this->config->alertmanager) {
            if (!$this->config->prometheusAlerts) {
                throw InvalidGenerationSettings::alertmanagerWithoutAlerts();
            }
            $receivers = $this->config->alertmanagerReceivers($input->pageReceiver, $input->ticketReceiver);
        } elseif ($input->pageReceiver !== null || $input->ticketReceiver !== null) {
            throw InvalidGenerationSettings::receiverOverrideWithoutAlertmanager();
        }

        $selector = $this->config->selector();
        $root = rtrim($input->outputDir ?? $this->config->outputDir, '/');
        if ($root === '') {
            throw InvalidGenerationSettings::invalidOutputDirectory();
        }

        $rules = ($this->rules)(new RulesInput($selector, $this->config->prometheusRules, $this->config->prometheusAlerts, $this->config->noEventsAlertHours));
        $dashboards = ($this->dashboards)(new DashboardsInput($selector));
        $sloth = ($this->sloth)(new SlothInput($selector, $this->config->sloth));
        $alertmanager = ($this->alertmanager)(new AlertmanagerInput($selector, $this->config->alertmanager, $receivers));

        $generated = $rules->files->merge($dashboards->files)->merge($sloth->files)->merge($alertmanager->files);
        $files = ($this->sync)(new SyncInput($generated, $root, $input->check));

        return new Output($root, $files, [...$rules->warnings, ...$sloth->warnings]);
    }
}
