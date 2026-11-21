<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Discovery;

/**
 * Generic measurement point (ruling 6): a service tagged TAG with attributes TYPE (a PointType value), ID and an
 * optional METRIC_LABEL; its class is reflected for class-level #[Slo] / #[IgnoreMonitoring].
 *
 * Discovery runs as a TYPE_BEFORE_OPTIMIZATION pass with priority 0, so a bridge that adds this tag from its own
 * compiler pass must register that pass with priority >= 10.
 */
final class PointTag
{
    public const TAG = 'msstc4symfony_slo.point';

    public const TYPE = 'type';

    public const ID = 'id';

    public const METRIC_LABEL = 'metric_label';
}
