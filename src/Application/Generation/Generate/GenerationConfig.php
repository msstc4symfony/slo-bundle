<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\Generate;

use InvalidArgumentException;

/**
 * The msstc4symfony_slo.generation settings slo:generate works with; the Grafana options belong to the Grafana adapter.
 *
 * @internal
 */
final readonly class GenerationConfig
{
    /** @var list<non-empty-string> */
    public array $components;

    /**
     * @param non-empty-string|null $application value of the `application` label, null until configured (slo:generate then refuses to run)
     * @param array<array-key, mixed> $components the configured list, checked here
     * @param non-empty-string $outputDir
     * @param positive-int|null $noEventsAlertHours
     *
     * @throws InvalidArgumentException for a value the configuration tree rejects
     */
    public function __construct(
        public ?string $application,
        array $components,
        public string $outputDir,
        public bool $prometheusRules = true,
        public bool $prometheusAlerts = true,
        public ?int $noEventsAlertHours = null,
        public bool $sloth = false,
    ) {
        if ($application === '') {
            throw new InvalidArgumentException('generation.selector.application must not be empty.');
        }
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
}
