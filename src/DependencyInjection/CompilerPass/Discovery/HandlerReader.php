<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery;

use Msstc4Symfony\SloBundle\Application\Discovery\DiscoveryErrors;
use Msstc4Symfony\SloBundle\Application\Discovery\HandlerInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionUnionType;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * A handler's #[Slo] means an SLO on the envelope of the handled message (spec 4.4), so the point is the message.
 *
 * @internal
 */
final readonly class HandlerReader
{
    public const TAG = 'messenger.message_handler';

    public function __construct(
        private AttributeReader $attributes,
        private DiscoveryErrors $errors,
    ) {
    }

    /**
     * @return array{list<HandlerInfo>, list<non-empty-string>} handlers sorted by handler and message, warnings
     */
    public function read(ContainerBuilder $container, MessengerRouting $routing): array
    {
        $handlers = [];
        /** @var array<non-empty-string, array<non-empty-string, bool>> $byMessage message => handler "Class::method" => declares #[Slo] */
        $byMessage = [];
        foreach ($container->findTaggedServiceIds(self::TAG) as $id => $tags) {
            $class = ServiceClass::reflect($container, $id);
            if (!$class instanceof ReflectionClass) {
                continue;
            }
            foreach ($tags as $tag) {
                if (!\is_array($tag)) {
                    continue;
                }
                $method = $this->nonEmptyString($tag['method'] ?? null) ?? '__invoke';
                if (!$class->hasMethod($method)) {
                    continue;
                }
                $reflection = $class->getMethod($method);
                $handlerKey = $class->getName() . '::' . $method;
                $handles = $this->nonEmptyString($tag['handles'] ?? null);
                foreach ($handles !== null ? [$handles] : $this->messageTypes($reflection) as $message) {
                    if (isset($byMessage[$message][$handlerKey])) {
                        continue;
                    }
                    $point = PointId::of(PointType::MessengerMessage, $message);
                    $onMethod = $this->attributes->apply($point, $reflection);
                    $onClass = $this->attributes->apply($point, $class);
                    $byMessage[$message][$handlerKey] = $onMethod || $onClass;
                    if ($onMethod || $onClass) {
                        $this->checkConcrete($message, $handlerKey);
                    }
                    $handlers[] = new HandlerInfo($class->getName(), $message, $routing->isAsync($message), $this->nonEmptyString($tag['from_transport'] ?? null), $method);
                }
            }
        }
        usort($handlers, static fn (HandlerInfo $a, HandlerInfo $b): int => [$a->handlerClass, $a->messageClass] <=> [$b->handlerClass, $b->messageClass]);
        ksort($byMessage, SORT_STRING);

        return [$handlers, $this->check($byMessage)];
    }

    /**
     * @param array<non-empty-string, array<non-empty-string, bool>> $byMessage
     *
     * @return list<non-empty-string> warnings
     */
    private function check(array $byMessage): array
    {
        $warnings = [];
        /** @var array<string, array<non-empty-string, bool>> $shortNames short name => message => declares #[Slo] */
        $shortNames = [];
        foreach ($byMessage as $message => $handlers) {
            $sloHandlers = array_keys(array_filter($handlers));
            $shortNames[$this->shortName($message)][$message] = $sloHandlers !== [];
            if ($sloHandlers === []) {
                continue;
            }
            $point = PointType::MessengerMessage->value . ':' . $message;
            if (\count($sloHandlers) > 1) {
                $this->errors->add(\sprintf('%s: #[Slo] is declared on %d handlers (%s); declare it on one handler, it measures the whole envelope.', $point, \count($sloHandlers), implode(', ', $sloHandlers)));
            } elseif (\count($handlers) > 1) {
                $warnings[] = \sprintf('%s has %d handlers (%s); its SLO measures the whole envelope.', $point, \count($handlers), implode(', ', array_keys($handlers)));
            }
        }

        foreach ($shortNames as $shortName => $messages) {
            foreach (array_keys(array_filter($messages)) as $message) {
                $others = array_diff_key($messages, [$message => true]);
                $sloOthers = array_keys(array_filter($others));
                $plainOthers = array_keys(array_diff_key($others, array_filter($others)));
                $point = PointType::MessengerMessage->value . ':' . $message;
                if ($sloOthers !== []) {
                    $this->errors->add(\sprintf('%s: short class name "%s" is also used by SLO message %s; metrics-bundle labels messages by short name.', $point, $shortName, implode(', ', $sloOthers)));
                } elseif ($plainOthers !== []) {
                    $warnings[] = \sprintf('%s: short class name "%s" is also used by message %s without an SLO; metrics-bundle labels messages by short name, so the SLO uses dedicated counters.', $point, $shortName, implode(', ', $plainOthers));
                }
            }
        }

        return $warnings;
    }

    /**
     * Runtime looks the point up by the dispatched message's concrete class, so an SLO on an interface or abstract
     * class would never record an event.
     *
     * @param non-empty-string $message
     */
    private function checkConcrete(string $message, string $handlerKey): void
    {
        if (!class_exists($message) && !interface_exists($message)) {
            return;
        }
        $reflection = new ReflectionClass($message);
        $kind = match (true) {
            $reflection->isInterface() => 'an interface',
            $reflection->isAbstract() => 'an abstract class',
            default => null,
        };
        if ($kind !== null) {
            $this->errors->add(\sprintf('%s: #[Slo] on %s handles %s; SLO events are matched by the concrete message class, declare the SLO on a handler of each concrete message instead.', PointType::MessengerMessage->value . ':' . $message, $handlerKey, $kind));
        }
    }

    /**
     * @return list<non-empty-string>
     */
    private function messageTypes(ReflectionMethod $method): array
    {
        $type = ($method->getParameters()[0] ?? null)?->getType();
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        $messages = [];
        foreach ($types as $candidate) {
            if ($candidate instanceof ReflectionNamedType && !$candidate->isBuiltin() && $candidate->getName() !== '') {
                $messages[] = $candidate->getName();
            }
        }

        return $messages;
    }

    private function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }

    /**
     * @return non-empty-string|null
     */
    private function nonEmptyString(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }
}
