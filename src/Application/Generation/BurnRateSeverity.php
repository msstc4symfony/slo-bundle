<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation;

/**
 * Who a burn-rate alert wakes up: page = now, ticket = during working hours. The value is the alert's `severity` label.
 *
 * @internal
 */
enum BurnRateSeverity: string
{
    case Page = 'page';
    case Ticket = 'ticket';
}
