<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Discovery;

/**
 * Generic measurement point (ruling 6): a service tagged TAG with attributes TYPE (a PointType value), ID and an
 * optional METRIC_LABEL; its class is reflected for class-level #[Slo] / #[IgnoreMonitoring].
 *
 * Discovery runs as a TYPE_BEFORE_OPTIMIZATION pass at priority -10; a bridge pass that adds the tag uses priority 10
 * or higher (after autoconfiguration at 100).
 */
final class PointTag
{
    public const TAG = 'msstc4symfony_slo.point';

    public const TYPE = 'type';

    public const ID = 'id';

    public const METRIC_LABEL = 'metric_label';
}
