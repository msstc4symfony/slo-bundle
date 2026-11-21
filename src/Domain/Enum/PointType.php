<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\Enum;

enum PointType: string
{
    case HttpRoute = 'http_route';
    case MessengerMessage = 'messenger_message';
    case LogicAction = 'logic_action';
    case Operation = 'operation';

    /**
     * Only HTTP latency can count error responses: the other point types have no error-but-measured event.
     */
    public function acceptsIncludeErrors(): bool
    {
        return $this === self::HttpRoute;
    }
}
