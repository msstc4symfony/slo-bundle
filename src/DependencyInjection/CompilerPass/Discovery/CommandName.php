<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery;

use Msstc4Symfony\SloBundle\Application\Discovery\ScheduledTaskPoint;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Mirrors AddScheduleMessengerPass of symfony/scheduler: which tasks become a RunCommandMessage and under which name.
 * The 8.1 rule (a `console.command` tag matching the task method, else the class-level tag when no tag names a method)
 * reduces to 7.4's "any `console.command` tag" because 7.4 tags never carry a method.
 *
 * @internal
 */
final class CommandName
{
    private const TAG = 'console.command';

    public static function isCommandTask(ContainerBuilder $container, string $serviceId, string $method = ScheduledTaskPoint::DEFAULT_METHOD): bool
    {
        return self::commandTag($container, $serviceId, $method) !== null;
    }

    /**
     * Tag attribute `command`, then #[AsCommand] name, then getDefaultName() where it still exists (Symfony 7.4);
     * aliases are dropped and a leading "|" (hidden command) is skipped.
     *
     * @return non-empty-string|null null when the task is not a command task or no name is found
     */
    public static function resolve(ContainerBuilder $container, string $serviceId, string $method = ScheduledTaskPoint::DEFAULT_METHOD): ?string
    {
        $tag = self::commandTag($container, $serviceId, $method);
        if ($tag === null) {
            return null;
        }
        $names = $tag['command'] ?? null;
        if (!\is_string($names)) {
            $class = ServiceClass::reflect($container, $serviceId);
            $names = $class instanceof ReflectionClass ? self::classNames($class) : null;
        }
        if ($names === null) {
            return null;
        }
        foreach (\array_slice(explode('|', $names), 0, 2) as $name) {
            if ($name !== '') {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private static function classNames(ReflectionClass $class): ?string
    {
        $attribute = $class->getAttributes(AsCommand::class)[0] ?? null;
        if ($attribute !== null) {
            return $attribute->newInstance()->name;
        }
        if (!method_exists($class->name, 'getDefaultName')) {
            return null;
        }
        $name = $class->name::getDefaultName();

        return \is_string($name) ? $name : null;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function commandTag(ContainerBuilder $container, string $serviceId, string $method): ?array
    {
        if (!$container->hasDefinition($serviceId)) {
            return null;
        }
        $tags = array_values(array_filter($container->getDefinition($serviceId)->getTag(self::TAG), \is_array(...)));
        $namesMethod = false;
        foreach ($tags as $tag) {
            if (($tag['method'] ?? ScheduledTaskPoint::DEFAULT_METHOD) === $method) {
                return $tag;
            }
            $namesMethod = $namesMethod || isset($tag['method']);
        }

        return $namesMethod ? null : $tags[0] ?? null;
    }
}
