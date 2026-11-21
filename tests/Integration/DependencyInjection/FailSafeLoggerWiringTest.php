<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\DependencyInjection;

use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Action as RecordEventAction;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Logging\FailSafeLogger;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\DeliveryStatusMapper;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\HttpSloObserver;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MessengerSloObserver;
use Msstc4Symfony\SloBundle\Infrastructure\Operation\OperationMonitor;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RoutePointIndex;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\SloCacheWarmer;
use Msstc4Symfony\SloBundle\Test\Integration\TestContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionNamedType;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * A failing application logger must never break fail-open recording: every runtime service logs through FailSafeLogger.
 */
final class FailSafeLoggerWiringTest extends TestCase
{
    private static ?ContainerBuilder $container = null;

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function runtimeServiceProvider(): iterable
    {
        foreach ([CompiledSloCatalog::class, RecordEventAction::class, RoutePointIndex::class, HttpSloObserver::class, DeliveryStatusMapper::class, MessengerSloObserver::class, OperationMonitor::class, SloCacheWarmer::class] as $class) {
            yield $class => [$class];
        }
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('runtimeServiceProvider')]
    public function testRuntimeServiceGetsTheFailSafeLogger(string $class): void
    {
        self::assertSame(FailSafeLogger::class, $this->loggerOf($class));
    }

    public function testNoBundleServiceGetsAnotherLogger(): void
    {
        $checked = 0;
        foreach ($this->container()->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();
            if ($class === null || !str_starts_with($class, 'Msstc4Symfony\SloBundle\\') || $class === FailSafeLogger::class || !class_exists($class)) {
                continue;
            }
            if ($this->loggerParameterIndex($class) === null) {
                continue;
            }
            $checked++;
            self::assertSame(FailSafeLogger::class, $this->loggerOf($class), $id);
        }

        self::assertGreaterThanOrEqual(\count(iterator_to_array(self::runtimeServiceProvider())), $checked);
    }

    /**
     * @param class-string $class
     */
    private function loggerOf(string $class): ?string
    {
        $index = $this->loggerParameterIndex($class);
        self::assertNotNull($index, $class . ' takes no logger');
        $arguments = $this->container()->getDefinition($class)->getArguments();
        $logger = $arguments[$index] ?? $arguments['$logger'] ?? null;

        return $logger instanceof Reference ? (string) $logger : null;
    }

    /**
     * @param class-string $class
     */
    private function loggerParameterIndex(string $class): ?int
    {
        foreach ((new ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === LoggerInterface::class) {
                return $parameter->getPosition();
            }
        }

        return null;
    }

    private function container(): ContainerBuilder
    {
        return self::$container ??= TestContainer::compile();
    }
}
