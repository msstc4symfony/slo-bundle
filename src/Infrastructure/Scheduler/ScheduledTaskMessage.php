<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Scheduler;

use Msstc4Symfony\SloBundle\Application\Discovery\ScheduledTaskPoint;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;

/**
 * Resolves the point of a message that Scheduler dispatches for a task. `instanceof` never autoloads, so this class
 * works without symfony/scheduler. Command names are matched as typed: aliases and abbreviations are not resolved.
 *
 * @internal
 */
final class ScheduledTaskMessage
{
    /**
     * Cheap and never throwing: for the middleware that sees every message of every bus.
     */
    public static function isTaskMessage(object $message): bool
    {
        return $message instanceof ServiceCallMessage || $message instanceof RunCommandMessage;
    }

    public static function pointOf(object $message): ?PointId
    {
        if ($message instanceof ServiceCallMessage) {
            $serviceId = $message->getServiceId();
            $method = $message->getMethod();

            return $serviceId !== '' && $method !== '' ? ScheduledTaskPoint::forService($serviceId, $method) : null;
        }
        if ($message instanceof RunCommandMessage) {
            $name = self::commandName($message->input);

            return $name !== null ? ScheduledTaskPoint::forCommand($name) : null;
        }

        return null;
    }

    /**
     * Tokenised as the handler's StringInput does: array arguments make Scheduler quote the name (`'app:foo' --x=1`).
     *
     * @return non-empty-string|null
     */
    private static function commandName(string $input): ?string
    {
        try {
            $name = (new StringInput($input))->getFirstArgument();
        } catch (InvalidArgumentException) {
            return null;
        }

        return $name !== null && $name !== '' ? $name : null;
    }
}
