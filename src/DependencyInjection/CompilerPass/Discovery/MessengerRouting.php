<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery;

use ReflectionAttribute;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Reads what FrameworkExtension stored for Messenger: the routing map of `messenger.senders_locator` (argument 0,
 * message type => sender names) and the failure transports of the failure listener (ruling 5).
 *
 * @internal
 */
final readonly class MessengerRouting
{
    public const SENDERS_LOCATOR = 'messenger.senders_locator';

    public const FAILURE_LISTENER = 'messenger.failure.send_failed_message_to_failure_transport_listener';

    private const TRANSPORT_PREFIX = 'messenger.transport.';

    /**
     * @param array<string, list<string>> $routing
     */
    private function __construct(private array $routing, private bool $enabled)
    {
    }

    public static function fromContainer(ContainerBuilder $container): self
    {
        $routing = [];
        $map = $container->hasDefinition(self::SENDERS_LOCATOR) ? ($container->getDefinition(self::SENDERS_LOCATOR)->getArguments()[0] ?? null) : null;
        if (\is_array($map)) {
            foreach ($map as $type => $senders) {
                if (\is_string($type) && \is_array($senders)) {
                    $routing[$type] = array_values(array_filter($senders, \is_string(...)));
                }
            }
        }

        return new self($routing, $container->hasDefinition(self::SENDERS_LOCATOR));
    }

    /**
     * Mirrors SendersLocator: the configured map (class, parents, interfaces, namespace wildcards, "*") and, only when
     * nothing there matches, #[AsMessage(transport: ...)] on the class hierarchy.
     */
    public function isAsync(string $message): bool
    {
        if (!$this->enabled) {
            return false;
        }
        $classes = $this->hierarchy($message);
        $types = $classes;
        $wildcard = $message . '\*';
        while (($position = strrpos($wildcard, '\\', -3)) !== false) {
            $wildcard = substr_replace($wildcard, '\*', $position);
            $types[] = $wildcard;
        }
        $types[] = '*';

        foreach ($types as $type) {
            if (($this->routing[$type] ?? []) !== []) {
                return true;
            }
        }

        return $this->routedByAttribute($classes);
    }

    /**
     * @return list<non-empty-string> sorted transport names
     */
    public static function failureTransports(ContainerBuilder $container, mixed $configured): array
    {
        if (\is_array($configured)) {
            return self::names($configured);
        }
        if (!$container->hasDefinition(self::FAILURE_LISTENER)) {
            return [];
        }

        $arguments = $container->getDefinition(self::FAILURE_LISTENER)->getArguments();
        $names = \is_array($arguments[2] ?? null) ? array_values($arguments[2]) : [];
        $locator = $arguments[0] ?? null;
        if ($locator instanceof Reference && $container->hasDefinition((string) $locator)) {
            $services = $container->getDefinition((string) $locator)->getArguments()[0] ?? null;
            foreach (\is_array($services) ? $services : [] as $service) {
                $reference = $service instanceof ServiceClosureArgument ? ($service->getValues()[0] ?? null) : $service;
                if ($reference instanceof Reference) {
                    $names[] = (string) $reference;
                }
            }
        }

        return self::names($names);
    }

    /**
     * @return non-empty-list<string> the type itself, then its parents and interfaces when it can be loaded
     */
    private function hierarchy(string $message): array
    {
        if (!class_exists($message) && !interface_exists($message)) {
            return [$message];
        }
        $parents = class_parents($message);
        $interfaces = class_implements($message);

        return [$message, ...($parents === false ? [] : array_values($parents)), ...($interfaces === false ? [] : array_values($interfaces))];
    }

    /**
     * @param list<string> $classes
     */
    private function routedByAttribute(array $classes): bool
    {
        if (!class_exists(AsMessage::class)) {
            return false;
        }
        foreach ($classes as $class) {
            if (!class_exists($class) && !interface_exists($class)) {
                continue;
            }
            foreach ((new ReflectionClass($class))->getAttributes(AsMessage::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $transport = $attribute->newInstance()->transport;
                if (!in_array($transport, [null, '', []], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $values
     *
     * @return list<non-empty-string>
     */
    private static function names(array $values): array
    {
        $names = [];
        foreach ($values as $value) {
            if (\is_string($value) && $value !== '') {
                $name = str_starts_with($value, self::TRANSPORT_PREFIX) ? substr($value, \strlen(self::TRANSPORT_PREFIX)) : $value;
                if ($name !== '') {
                    $names[$name] = $name;
                }
            }
        }
        sort($names, SORT_STRING);

        return $names;
    }
}
