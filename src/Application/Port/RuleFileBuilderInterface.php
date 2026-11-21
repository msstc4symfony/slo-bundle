<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;

/**
 * Renders Prometheus rule files; one rule group per SLO.
 */
interface RuleFileBuilderInterface
{
    /**
     * @param list<BoundObjective> $objectives
     */
    public function recordingRules(Selector $selector, array $objectives): string;

    /**
     * @param list<BoundObjective> $objectives
     * @param positive-int|null $noEventsAlertHours adds SloNoEvents when set
     */
    public function alertRules(Selector $selector, array $objectives, ?int $noEventsAlertHours): string;
}
