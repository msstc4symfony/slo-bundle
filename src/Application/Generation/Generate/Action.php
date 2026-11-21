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
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
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
        $application = $this->config->application;
        if ($application === null || $application === '') {
            throw InvalidGenerationSettings::missingApplication();
        }
        $root = rtrim($input->outputDir ?? $this->config->outputDir, '/');
        if ($root === '') {
            throw InvalidGenerationSettings::invalidOutputDirectory();
        }

        $selector = new Selector($application, $this->config->components);
        $rules = ($this->rules)(new RulesInput($selector, $this->config->prometheusRules, $this->config->prometheusAlerts, $this->config->noEventsAlertHours));
        $dashboards = ($this->dashboards)(new DashboardsInput($selector, $this->config->datasourceUid, $this->config->folder, $this->config->ignoredRoutes));
        $sloth = ($this->sloth)(new SlothInput($selector, $this->config->sloth));

        $files = ($this->sync)(new SyncInput($rules->files->merge($dashboards->files)->merge($sloth->files), $root, $input->check));

        return new Output($root, $files, [...$rules->warnings, ...$sloth->warnings]);
    }
}
