<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Discovery;

use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;

/**
 * Point ids of Symfony Scheduler tasks, shared by discovery and the worker listener so both name a run the same way:
 * a service task is `<service id>::<method>` (as in ServiceCallMessage), a command task `command:<command name>`.
 *
 * @internal
 */
final class ScheduledTaskPoint
{
    public const TAG = 'scheduler.task';

    public const DEFAULT_METHOD = '__invoke';

    public const COMMAND_PREFIX = 'command:';

    /**
     * @param non-empty-string $serviceId
     * @param non-empty-string $method
     */
    public static function forService(string $serviceId, string $method): PointId
    {
        return PointId::of(PointType::ScheduledTask, $serviceId . '::' . $method);
    }

    /**
     * @param non-empty-string $name
     */
    public static function forCommand(string $name): PointId
    {
        return PointId::of(PointType::ScheduledTask, self::COMMAND_PREFIX . $name);
    }
}
