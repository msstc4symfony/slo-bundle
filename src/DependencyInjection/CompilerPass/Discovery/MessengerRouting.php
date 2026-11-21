<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery;

use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

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
    private function __construct(private array $routing)
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

        return new self($routing);
    }

    /**
     * Mirrors SendersLocator: the class, its parents, interfaces, namespace wildcards and "*".
     */
    public function isAsync(string $message): bool
    {
        $types = [$message];
        if (class_exists($message) || interface_exists($message)) {
            $parents = class_parents($message);
            $interfaces = class_implements($message);
            $types = [...$types, ...($parents === false ? [] : array_values($parents)), ...($interfaces === false ? [] : array_values($interfaces))];
        }
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

        return false;
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
