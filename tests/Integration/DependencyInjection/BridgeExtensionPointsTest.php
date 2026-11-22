<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\DependencyInjection;

use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Discovery\PointTag;
use Msstc4Symfony\SloBundle\Application\Operation\OperationMonitor;
use Msstc4Symfony\SloBundle\Application\Port\ExpectedFailureClassifierInterface;
use Msstc4Symfony\SloBundle\Application\Port\OperationMonitorInterface;
use Msstc4Symfony\SloBundle\DependencyInjection\ExpectedFailureClassifierSelector;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Test\Fixtures\Bridge\ClassifierConsumer;
use Msstc4Symfony\SloBundle\Test\Fixtures\Bridge\DecoratingOperationMonitor;
use Msstc4Symfony\SloBundle\Test\Fixtures\Bridge\LatencyAction;
use Msstc4Symfony\SloBundle\Test\Fixtures\Bridge\LogicHistogramMetric;
use Msstc4Symfony\SloBundle\Test\Integration\CallbackPass;
use Msstc4Symfony\SloBundle\Test\Integration\TestContainer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Container contracts that slo-bridge-logic and slo-bridge-profiling build on.
 */
final class BridgeExtensionPointsTest extends TestCase
{
    public function testDiscoverySeesMetricEnumsAddedByLaterBundles(): void
    {
        $container = TestContainer::compile([], static function (ContainerBuilder $container): void {
            self::withoutRouter($container);
            $container->register(LatencyAction::class, LatencyAction::class)
                ->addTag(PointTag::TAG, [PointTag::TYPE => 'logic_action', PointTag::ID => LatencyAction::class, PointTag::METRIC_LABEL => LatencyAction::class])
            ;
            // A bundle registered after SloBundle appends its metric enum at the same priority, like metrics-bridge-logic.
            $container->addCompilerPass(new CallbackPass(static function (ContainerBuilder $container): void {
                $enums = $container->getParameter('msstc4symfony_metrics.metric_enums');
                $container->setParameter('msstc4symfony_metrics.metric_enums', [...(\is_array($enums) ? $enums : []), LogicHistogramMetric::class]);
            }), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
        });

        $catalog = $container->get(CompiledSloCatalog::class);
        self::assertInstanceOf(CompiledSloCatalog::class, $catalog);
        $definition = $catalog->get(SloName::fromString('bridge.latency'));
        self::assertInstanceOf(SloDefinition::class, $definition);
        $resolver = $container->get(MetricBindingResolver::class);
        self::assertInstanceOf(MetricBindingResolver::class, $resolver);

        self::assertSame([0.1, 0.25], $catalog->extras()->metricBuckets['logic_action_duration_histogram_seconds'] ?? null);
        self::assertFalse($resolver->isDedicated($definition, ObjectiveKind::Latency));
    }

    public function testOperationMonitorCanBeDecorated(): void
    {
        $container = TestContainer::compile([], static function (ContainerBuilder $container): void {
            self::withoutRouter($container);
            $container->register(DecoratingOperationMonitor::class, DecoratingOperationMonitor::class)
                ->setDecoratedService(OperationMonitorInterface::class)
                ->setArguments([new Reference('.inner')])
            ;
        });

        $monitor = $container->get(OperationMonitorInterface::class);
        self::assertInstanceOf(DecoratingOperationMonitor::class, $monitor);
        self::assertInstanceOf(OperationMonitor::class, $monitor->inner);
    }

    public function testExpectedFailureClassifierIsInjectable(): void
    {
        $container = TestContainer::compile([], static function (ContainerBuilder $container): void {
            $container->register(ClassifierConsumer::class, ClassifierConsumer::class)->setAutowired(true)->setPublic(true);
            $container->setAlias('test.classifier', ExpectedFailureClassifierInterface::class)->setPublic(true);
        });
        $selected = ExpectedFailureClassifierSelector::select(new ContainerBuilder());

        $consumer = $container->get(ClassifierConsumer::class);
        self::assertInstanceOf(ClassifierConsumer::class, $consumer);
        self::assertInstanceOf($selected, $consumer->classifier);
        self::assertSame($container->get($selected), $container->get('test.classifier'));
        self::assertSame($container->get($selected), $consumer->classifier);
    }

    /**
     * The stub router of TestContainer cannot be instantiated; the bundle takes the router as optional.
     */
    private static function withoutRouter(ContainerBuilder $container): void
    {
        $container->removeDefinition('router');
    }
}
