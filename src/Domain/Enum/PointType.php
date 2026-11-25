<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\Enum;

enum PointType: string
{
    case HttpRoute = 'http_route';
    case MessengerMessage = 'messenger_message';
    case LogicAction = 'logic_action';
    case Operation = 'operation';
    case ScheduledTask = 'scheduled_task';

    /**
     * Only HTTP latency can count error responses: the other point types have no error-but-measured event.
     *
     * @internal discovery helper, not covered by semantic versioning
     */
    public function acceptsIncludeErrors(): bool
    {
        return $this === self::HttpRoute;
    }
}
