<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Coverage\Analyze;

/**
 * Case order is the report order.
 *
 * @internal
 */
enum CoverageKind: string
{
    case HttpRoute = 'http_route';
    case MessengerHandler = 'messenger_handler';
    case LogicAction = 'logic_action';
    case Operation = 'operation';
    case ScheduledTask = 'scheduled_task';

    public function position(): int
    {
        return match ($this) {
            self::HttpRoute => 0,
            self::MessengerHandler => 1,
            self::LogicAction => 2,
            self::Operation => 3,
            self::ScheduledTask => 4,
        };
    }
}
