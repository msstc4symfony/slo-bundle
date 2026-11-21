<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Binding;

/**
 * @internal
 */
enum SeriesKind
{
    case Counter;

    /** Events are counted by the `_count` series, latency by a `_bucket`. */
    case Histogram;
}
