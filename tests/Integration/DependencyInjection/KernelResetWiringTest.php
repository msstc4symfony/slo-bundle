<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\DependencyInjection;

use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Action as RecordEventAction;
use Msstc4Symfony\SloBundle\Application\Operation\OperationMonitor;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\DeliveryStatusMapper;
use Msstc4Symfony\SloBundle\Infrastructure\Scheduler\ScheduledTaskRuns;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RoutePointIndex;
use Msstc4Symfony\SloBundle\Test\Integration\TestContainer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Retrying failed resolutions and forgetting warnings between messages and requests relies on the kernel.reset tag.
 */
final class KernelResetWiringTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<ResetInterface>}>
     */
    public static function resettableServiceProvider(): iterable
    {
        foreach ([CompiledSloCatalog::class, RecordEventAction::class, RoutePointIndex::class, DeliveryStatusMapper::class, OperationMonitor::class] as $class) {
            yield $class => [$class];
        }
        // Registered only with symfony/scheduler and symfony/messenger installed.
        if (class_exists(ServiceCallMessage::class) && class_exists(WorkerMessageReceivedEvent::class)) {
            yield ScheduledTaskRuns::class => [ScheduledTaskRuns::class];
        }
    }

    /**
     * @param class-string<ResetInterface> $class
     */
    #[DataProvider('resettableServiceProvider')]
    public function testStatefulRuntimeServiceIsResetByTheKernel(string $class): void
    {
        self::assertSame([['method' => 'reset']], TestContainer::compile()->getDefinition($class)->getTag('kernel.reset'));
    }

    public function testEveryResettableBundleServiceIsTagged(): void
    {
        $resettable = [];
        foreach (TestContainer::compile()->getDefinitions() as $id => $definition) {
            $class = $definition->getClass();
            if ($class === null || !str_starts_with($class, 'Msstc4Symfony\SloBundle\\') || !is_a($class, ResetInterface::class, true)) {
                continue;
            }
            $resettable[] = $class;
            self::assertSame([['method' => 'reset']], $definition->getTag('kernel.reset'), $id);
        }
        sort($resettable);

        $expected = array_keys(iterator_to_array(self::resettableServiceProvider()));
        sort($expected);
        self::assertSame($expected, $resettable);
    }
}
