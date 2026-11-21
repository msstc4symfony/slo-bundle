<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Binding;

/**
 * @internal
 */
enum SeriesKind
{
    case Counter;

    /** Events are counted by the histogram's event count, latency by one of its buckets. */
    case Histogram;
}
