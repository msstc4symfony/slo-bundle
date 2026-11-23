<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

use Msstc4Symfony\SloBundle\Application\Generation\AlertmanagerReceivers;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;

/**
 * Renders the Alertmanager routing of the generated SLO alerts.
 *
 * @internal
 */
interface AlertmanagerFileBuilderInterface
{
    /**
     * A complete Alertmanager configuration: the SLO route under a root route, the receivers without integrations
     * and the inhibit rules.
     */
    public function full(Selector $selector, AlertmanagerReceivers $receivers): string;

    /**
     * A fragment with the top-level keys `routes` and `inhibit_rules`, to paste into an existing configuration.
     */
    public function routes(Selector $selector, AlertmanagerReceivers $receivers): string;
}
