<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Alertmanager;

use Msstc4Symfony\SloBundle\Application\Generation\AlertmanagerReceivers;
use Msstc4Symfony\SloBundle\Application\Generation\AlertNames;
use Msstc4Symfony\SloBundle\Application\Generation\BurnRateSeverity;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Application\Port\AlertmanagerFileBuilderInterface;
use Msstc4Symfony\SloBundle\Infrastructure\Yaml\DeterministicYaml;
use Override;

/**
 * The generated alerts carry no component label, so the SLO route matches on `application` only. Grouping timings
 * are left to Alertmanager's defaults.
 *
 * Shapes: SeverityRouteShape routes one severity to its receiver; SloRouteShape is the route of all SLO alerts of one
 * application; InhibitRuleShape lets a page silence the ticket of the same objective.
 *
 * @phpstan-type SeverityRouteShape array{matchers: list<non-empty-string>, receiver: non-empty-string}
 * @phpstan-type SloRouteShape array{group_by: list<non-empty-string>, matchers: list<non-empty-string>, routes: list<SeverityRouteShape>}
 * @phpstan-type InhibitRuleShape array{equal: list<non-empty-string>, source_matchers: list<non-empty-string>, target_matchers: list<non-empty-string>}
 *
 * @internal
 */
final readonly class AlertmanagerFileBuilder implements AlertmanagerFileBuilderInterface
{
    /**
     * Root receiver of the full file: Alertmanager requires one, no non-SLO alerts are expected there.
     */
    public const UNROUTED_RECEIVER = 'slo-unrouted';

    private const ALERT_LABELS = ['application', 'slo', 'objective'];

    public function __construct(
        private DeterministicYaml $yaml,
    ) {
    }

    #[Override]
    public function full(Selector $selector, AlertmanagerReceivers $receivers): string
    {
        $names = array_values(array_unique([self::UNROUTED_RECEIVER, ...$receivers->names()]));

        return $this->yaml->dump([
            'route' => ['receiver' => self::UNROUTED_RECEIVER, 'routes' => [$this->sloRoute($selector, $receivers)]],
            'receivers' => array_map(static fn (string $name): array => ['name' => $name], $names),
            'inhibit_rules' => [$this->inhibitRule()],
        ]);
    }

    #[Override]
    public function routes(Selector $selector, AlertmanagerReceivers $receivers): string
    {
        return $this->yaml->dump([
            'routes' => [$this->sloRoute($selector, $receivers)],
            'inhibit_rules' => [$this->inhibitRule()],
        ]);
    }

    /**
     * @return SloRouteShape
     */
    private function sloRoute(Selector $selector, AlertmanagerReceivers $receivers): array
    {
        return [
            'group_by' => self::ALERT_LABELS,
            'matchers' => [
                \sprintf('alertname=~"%s|%s"', AlertNames::BURN, AlertNames::NO_EVENTS),
                \sprintf('application="%s"', $selector->application),
            ],
            'routes' => [
                ['matchers' => [$this->severity(BurnRateSeverity::Page)], 'receiver' => $receivers->page],
                ['matchers' => [$this->severity(BurnRateSeverity::Ticket)], 'receiver' => $receivers->ticket],
            ],
        ];
    }

    /**
     * SloNoEvents is never inhibited: "no events" and "burning fast" exclude each other.
     *
     * @return InhibitRuleShape
     */
    private function inhibitRule(): array
    {
        $burn = \sprintf('alertname="%s"', AlertNames::BURN);

        return [
            'equal' => self::ALERT_LABELS,
            'source_matchers' => [$burn, $this->severity(BurnRateSeverity::Page)],
            'target_matchers' => [$burn, $this->severity(BurnRateSeverity::Ticket)],
        ];
    }

    /**
     * @return non-empty-string
     */
    private function severity(BurnRateSeverity $severity): string
    {
        return \sprintf('severity="%s"', $severity->value);
    }
}
