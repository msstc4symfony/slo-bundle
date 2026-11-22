<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Bridge;

use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;

#[Slo(name: 'bridge.latency', objectives: [new Latency('250ms', 99.0)])]
final class LatencyAction
{
}
