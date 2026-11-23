<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\Generate;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Generation\AlertmanagerReceivers;
use Msstc4Symfony\SloBundle\Application\Generation\Generate\Exception\InvalidGenerationSettings;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;

/**
 * The msstc4symfony_slo.generation settings slo:generate works with; the Grafana options belong to the Grafana adapter.
 *
 * @internal
 */
final readonly class GenerationConfig
{
    public const LABEL_VALUE = '/^[A-Za-z0-9_.:-]{1,128}$/D';

    /** @var non-empty-string|null value of the `application` label, null until configured (slo:generate then refuses to run) */
    public ?string $application;

    /** @var list<non-empty-string> */
    public array $components;

    /**
     * @param string|null $application '' (an env placeholder resolved to nothing) counts as not configured
     * @param array<array-key, mixed> $components the configured list, checked here
     * @param non-empty-string $outputDir
     * @param positive-int|null $noEventsAlertHours
     *
     * @throws InvalidArgumentException for a value the configuration tree rejects
     */
    public function __construct(
        ?string $application,
        array $components,
        public string $outputDir,
        public bool $prometheusRules = true,
        public bool $prometheusAlerts = true,
        public ?int $noEventsAlertHours = null,
        public bool $sloth = false,
        public bool $alertmanager = false,
        private string $pageReceiver = 'slo-page',
        private string $ticketReceiver = 'slo-ticket',
    ) {
        $this->application = $application === '' ? null : $application;
        $valid = array_values(array_filter($components, static fn (mixed $component): bool => \is_string($component) && $component !== ''));
        if ($valid !== $components) {
            throw new InvalidArgumentException(\sprintf('generation.selector.components must be a list of non-empty strings, got %s.', json_encode($components)));
        }
        $this->components = $valid;
        if ($outputDir === '') {
            throw new InvalidArgumentException('generation.output_dir must not be empty.');
        }
        if ($noEventsAlertHours !== null && $noEventsAlertHours < 1) {
            throw new InvalidArgumentException(\sprintf('generation.prometheus.no_events_alert_hours must be at least 1, got %d.', $noEventsAlertHours));
        }
    }

    /**
     * Env placeholders pass the configuration tree unresolved, so the resolved values are checked here.
     *
     * @throws InvalidGenerationSettings
     */
    public function selector(): Selector
    {
        if ($this->application === null) {
            throw InvalidGenerationSettings::missingApplication();
        }
        if (preg_match(self::LABEL_VALUE, $this->application) !== 1) {
            throw InvalidGenerationSettings::invalidLabelValue('application', $this->application);
        }
        foreach ($this->components as $component) {
            if (preg_match(self::LABEL_VALUE, $component) !== 1) {
                throw InvalidGenerationSettings::invalidLabelValue('component', $component);
            }
        }

        return new Selector($this->application, $this->components);
    }

    /**
     * Env placeholders pass the configuration tree unresolved, so the resolved names are checked here.
     *
     * @throws InvalidGenerationSettings
     */
    public function alertmanagerReceivers(?string $pageOverride, ?string $ticketOverride): AlertmanagerReceivers
    {
        $page = $pageOverride ?? $this->pageReceiver;
        $ticket = $ticketOverride ?? $this->ticketReceiver;
        if ($page === '') {
            throw InvalidGenerationSettings::invalidReceiver('page', $page);
        }
        if ($ticket === '') {
            throw InvalidGenerationSettings::invalidReceiver('ticket', $ticket);
        }

        try {
            return new AlertmanagerReceivers($page, $ticket);
        } catch (InvalidArgumentException $e) {
            throw new InvalidGenerationSettings($e->getMessage(), $e->getCode(), previous: $e);
        }
    }
}
