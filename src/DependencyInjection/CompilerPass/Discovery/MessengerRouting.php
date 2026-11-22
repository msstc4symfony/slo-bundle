<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery;

use ReflectionAttribute;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Argument\ServiceClosureArgument;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Reads what FrameworkExtension stored for Messenger: the routing map of `messenger.senders_locator` (argument 0,
 * message type => sender names), the transports (`messenger.receiver` tags) and the failure transports of the failure
 * listener (ruling 5).
 *
 * @internal
 */
final readonly class MessengerRouting
{
    public const SENDERS_LOCATOR = 'messenger.senders_locator';

    public const FAILURE_LISTENER = 'messenger.failure.send_failed_message_to_failure_transport_listener';

    private const TRANSPORT_PREFIX = 'messenger.transport.';

    private const RECEIVER_TAG = 'messenger.receiver';

    private const SCHEDULE_DSN = 'schedule://';

    private const FRAMEWORK_NAMESPACE = 'Symfony\\';

    /**
     * @param array<string, list<non-empty-string>> $routing sender names without the transport service prefix
     * @param array<non-empty-string, bool> $transports transport name => a worker consumes it for SLO events (false for `sync://` and failure transports)
     */
    private function __construct(
        private array $routing,
        private bool $enabled,
        private array $transports,
        private bool $receivesForeignMessages,
    ) {
    }

    /**
     * A transport that is consumable, not a failure or Scheduler transport, and targeted by no routing entry and no
     * #[AsMessage] of a handled message is read by a worker for messages another service produces, so the handled
     * messages this app does not route may arrive there.
     *
     * @param list<non-empty-string> $failureTransports
     * @param list<non-empty-string> $messages every message type handled in this app
     */
    public static function fromContainer(ContainerBuilder $container, array $failureTransports, array $messages): self
    {
        $routing = [];
        $map = $container->hasDefinition(self::SENDERS_LOCATOR) ? ($container->getDefinition(self::SENDERS_LOCATOR)->getArguments()[0] ?? null) : null;
        if (\is_array($map)) {
            foreach ($map as $type => $senders) {
                if (\is_string($type) && \is_array($senders)) {
                    $routing[$type] = self::senderNames($senders);
                }
            }
        }

        $targets = self::routingTargets($routing, $messages);

        $transports = [];
        $receivesForeignMessages = false;
        foreach ($container->findTaggedServiceIds(self::RECEIVER_TAG) as $id => $tags) {
            foreach ($tags as $tag) {
                $alias = \is_array($tag) ? ($tag['alias'] ?? null) : null;
                if (!\is_string($alias) || $alias === '') {
                    continue;
                }
                $failure = ($tag['is_failure_transport'] ?? false) === true || \in_array($alias, $failureTransports, true);
                $consumable = ($tag['is_consumable'] ?? true) !== false;
                $transports[$alias] = $consumable && !$failure;
                if ($consumable && !$failure && !self::isScheduled($container, $id) && !\in_array($alias, $targets, true)) {
                    $receivesForeignMessages = true;
                }
            }
        }

        return new self($routing, $container->hasDefinition(self::SENDERS_LOCATOR), $transports, $receivesForeignMessages);
    }

    /**
     * Worker-consumed, i.e. measured by metrics-bundle: bound by `from_transport` to a worker transport (not `sync://`,
     * not a failure transport); else routed (SendersLocator order: the configured map with parents, interfaces,
     * namespace wildcards and "*", then only when nothing matched #[AsMessage(transport: ...)]) to at least one
     * non-`sync://` sender; else, when the message is not routed here, the app has a receive-only transport. Framework
     * messages (`Symfony\*`) are produced by the app itself, so an unrouted one stays synchronous.
     *
     * @param non-empty-string|null $fromTransport
     */
    public function isAsync(string $message, ?string $fromTransport = null): bool
    {
        if (!$this->enabled) {
            return false;
        }
        if ($fromTransport !== null) {
            return $this->transports[self::transportName($fromTransport)] ?? true;
        }

        $classes = self::hierarchy($message);
        $senders = $this->mapSenders($message, $classes);
        if ($senders === []) {
            $senders = self::attributeTransports($classes);
        }
        if ($senders !== []) {
            foreach ($senders as $sender) {
                if ($this->transports[$sender] ?? true) {
                    return true;
                }
            }

            return false;
        }

        return $this->receivesForeignMessages && !str_starts_with($message, self::FRAMEWORK_NAMESPACE);
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
     * @param array<string, list<non-empty-string>> $routing
     * @param list<non-empty-string> $messages
     *
     * @return list<non-empty-string> transports a routing entry or an #[AsMessage] of a handled message sends to
     */
    private static function routingTargets(array $routing, array $messages): array
    {
        $targets = array_merge([], ...array_values($routing));
        foreach ($messages as $message) {
            $targets = [...$targets, ...self::attributeTransports(self::hierarchy($message))];
        }

        return $targets;
    }

    /**
     * Scheduler transports (`schedule://<name>`, registered by AddScheduleMessengerPass) carry the app's own recurring
     * messages, not messages of another service. The DSN is read positionally, by name or from a ChildDefinition parent.
     */
    private static function isScheduled(ContainerBuilder $container, string $id): bool
    {
        for ($depth = 0; $depth < 10 && $container->hasDefinition($id); $depth++) {
            $definition = $container->getDefinition($id);
            $arguments = $definition->getArguments();
            $dsn = $arguments[0] ?? $arguments['index_0'] ?? $arguments['$dsn'] ?? null;
            if ($dsn !== null || !$definition instanceof ChildDefinition) {
                return \is_string($dsn) && str_starts_with($dsn, self::SCHEDULE_DSN);
            }
            $id = $definition->getParent();
        }

        return false;
    }

    /**
     * @return non-empty-list<string> the type itself, then its parents and interfaces when it can be loaded
     */
    private static function hierarchy(string $message): array
    {
        if (!class_exists($message) && !interface_exists($message)) {
            return [$message];
        }
        $parents = class_parents($message);
        $interfaces = class_implements($message);

        return [$message, ...($parents === false ? [] : array_values($parents)), ...($interfaces === false ? [] : array_values($interfaces))];
    }

    /**
     * @param non-empty-list<string> $classes
     *
     * @return list<non-empty-string>
     */
    private function mapSenders(string $message, array $classes): array
    {
        $types = $classes;
        $wildcard = $message . '\\*';
        while (($position = strrpos($wildcard, '\\', -3)) !== false) {
            $wildcard = substr_replace($wildcard, '\\*', $position);
            $types[] = $wildcard;
        }
        $types[] = '*';

        $senders = [];
        foreach ($types as $type) {
            $senders = [...$senders, ...($this->routing[$type] ?? [])];
        }

        return array_values(array_unique($senders));
    }

    /**
     * @param list<string> $classes
     *
     * @return list<non-empty-string>
     */
    private static function attributeTransports(array $classes): array
    {
        if (!class_exists(AsMessage::class)) {
            return [];
        }
        $transports = [];
        foreach ($classes as $class) {
            if (!class_exists($class) && !interface_exists($class)) {
                continue;
            }
            foreach ((new ReflectionClass($class))->getAttributes(AsMessage::class, ReflectionAttribute::IS_INSTANCEOF) as $attribute) {
                $transport = $attribute->newInstance()->transport;
                $transports = [...$transports, ...self::senderNames(\is_array($transport) ? $transport : [$transport])];
            }
        }

        return $transports;
    }

    /**
     * @param array<mixed> $senders
     *
     * @return list<non-empty-string>
     */
    private static function senderNames(array $senders): array
    {
        $names = [];
        foreach ($senders as $sender) {
            if (\is_string($sender) && $sender !== '') {
                $names[] = self::transportName($sender);
            }
        }

        return $names;
    }

    /**
     * @param non-empty-string $sender
     *
     * @return non-empty-string
     */
    private static function transportName(string $sender): string
    {
        $name = str_starts_with($sender, self::TRANSPORT_PREFIX) ? substr($sender, \strlen(self::TRANSPORT_PREFIX)) : $sender;

        return $name !== '' ? $name : $sender;
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
