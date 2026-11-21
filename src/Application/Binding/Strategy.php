<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Binding;

/**
 * How an objective is measured, decided by MetricBindingResolver.
 *
 * @internal
 */
enum Strategy: string
{
    case ExistingSeries = 'existing_series';
    case Dedicated = 'dedicated';
}
