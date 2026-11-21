<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\Generate;

/**
 * The msstc4symfony_slo.generation settings (and the coverage ignore list) slo:generate works with.
 *
 * @internal
 */
final readonly class GenerationConfig
{
    /**
     * @param string|null $application value of the `application` label, required to generate
     * @param list<string> $components
     * @param positive-int|null $noEventsAlertHours
     * @param non-empty-string $datasourceUid
     * @param non-empty-string $folder
     * @param list<string> $ignoredRoutes
     */
    public function __construct(
        public ?string $application,
        public array $components,
        public string $outputDir,
        public bool $prometheusRules = true,
        public bool $prometheusAlerts = true,
        public ?int $noEventsAlertHours = null,
        public string $datasourceUid = 'prometheus',
        public string $folder = 'SLO',
        public array $ignoredRoutes = [],
        public bool $sloth = false,
    ) {
    }
}
