<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation;

/**
 * `alertname` labels shared by the Prometheus rules and the Alertmanager routes that match them.
 *
 * @internal
 */
final readonly class AlertNames
{
    public const BURN = 'SloErrorBudgetBurn';

    public const NO_EVENTS = 'SloNoEvents';
}
