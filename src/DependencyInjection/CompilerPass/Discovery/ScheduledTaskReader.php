<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery;

use Msstc4Symfony\SloBundle\Application\Discovery\DiscoveryErrors;
use Msstc4Symfony\SloBundle\Application\Discovery\ScheduledTaskPoint;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Symfony Scheduler tasks from `scheduler.task` tags (#[AsCronTask] / #[AsPeriodicTask] autoconfiguration). Without
 * symfony/scheduler nothing carries the tag and nothing is discovered.
 *
 * @internal
 */
final readonly class ScheduledTaskReader
{
    public function __construct(
        private AttributeReader $attributes,
        private DiscoveryErrors $errors,
    ) {
    }

    /**
     * Repeatable task attributes give one tag per schedule; they share one point, so its #[Slo] is declared once.
     *
     * @return ReadResult<PointId> points sorted by point
     */
    public function read(ContainerBuilder $container): ReadResult
    {
        $points = [];
        foreach ($container->findTaggedServiceIds(ScheduledTaskPoint::TAG) as $id => $tags) {
            $class = ServiceClass::reflect($container, $id);
            if (!$class instanceof ReflectionClass || $id === '') {
                continue;
            }
            foreach ($tags as $tag) {
                $method = $this->method($tag);
                $command = CommandName::isCommandTask($container, $id, $method);
                $point = $command ? $this->commandPoint($container, $id, $method, $class) : ScheduledTaskPoint::forService($id, $method);
                if (!$point instanceof PointId || isset($points[$point->toString()])) {
                    continue;
                }
                $points[$point->toString()] = $point;
                $this->attributes->apply($point, $class);
                // Command tasks read #[Slo] from the class only; a missing method fails in Symfony at run time.
                if (!$command && $class->hasMethod($method)) {
                    $this->attributes->apply($point, $class->getMethod($method));
                }
            }
        }
        ksort($points, SORT_STRING);

        return new ReadResult(array_values($points), []);
    }

    /**
     * @param ReflectionClass<object> $class
     */
    private function commandPoint(ContainerBuilder $container, string $id, string $method, ReflectionClass $class): ?PointId
    {
        $name = CommandName::resolve($container, $id, $method);
        if ($name !== null) {
            return ScheduledTaskPoint::forCommand($name);
        }
        if (AttributeReader::declaresAny($class)) {
            $this->errors->add(\sprintf('service "%s": scheduled command task declares #[Slo] or #[IgnoreMonitoring] but cannot resolve the command name; set it with #[AsCommand] or the "command" attribute of the console.command tag.', $id));
        }

        return null;
    }

    /**
     * Class-level task attributes leave `method` null in the tag.
     *
     * @return non-empty-string
     */
    private function method(mixed $tag): string
    {
        $method = \is_array($tag) ? $tag['method'] ?? null : null;

        return \is_string($method) && $method !== '' ? $method : ScheduledTaskPoint::DEFAULT_METHOD;
    }
}
