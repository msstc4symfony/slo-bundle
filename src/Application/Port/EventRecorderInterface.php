<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Port;

use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Input;

/**
 * Driving port for bridges: records one observation of a point for every SLO declared on it.
 * Fail-open: never throws, failures are logged on the slo_bundle channel.
 */
interface EventRecorderInterface
{
    public function record(Input $input): void;
}
