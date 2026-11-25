<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\DependencyInjection;

use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\CleanupTask;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\ReportTasks;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\UnmonitoredTask;
use Msstc4Symfony\SloBundle\Test\Integration\Kernel\SchedulerTestKernel;
use Override;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Scheduler\Schedule;

/**
 * Proves the scheduler.task tag attributes (`method`, `arguments`) as FrameworkBundle's autoconfiguration writes them.
 */
#[RequiresMethod(Schedule::class, 'add')]
final class SchedulerFrameworkDiscoveryTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        (new Filesystem())->remove(SchedulerTestKernel::cacheRoot());
    }

    #[Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove(SchedulerTestKernel::cacheRoot());
    }

    public function testDumpedCatalogHoldsAttributeTasks(): void
    {
        $kernel = new SchedulerTestKernel();
        $kernel->boot();

        try {
            $catalog = $kernel->getContainer()->get(CompiledSloCatalog::class);
            self::assertInstanceOf(CompiledSloCatalog::class, $catalog);

            self::assertSame(['scheduled_task:' . CleanupTask::class . '::__invoke'], $this->points($catalog, 'cleanup.run'));
            self::assertSame(['scheduled_task:command:app:sync-partners'], $this->points($catalog, 'partners.sync'));
            self::assertSame(['scheduled_task:' . ReportTasks::class . '::daily'], $this->points($catalog, 'report.daily'));
            self::assertSame([], $catalog->conflicts());
            self::assertSame([
                CleanupTask::class . '::__invoke',
                ReportTasks::class . '::daily',
                ReportTasks::class . '::weekly',
                UnmonitoredTask::class . '::__invoke',
                'command:app:sync-partners',
            ], $catalog->extras()->scheduledTasks);
        } finally {
            $kernel->shutdown();
        }
    }

    /**
     * @param non-empty-string $name
     *
     * @return list<non-empty-string>
     */
    private function points(CompiledSloCatalog $catalog, string $name): array
    {
        $definition = $catalog->get(SloName::fromString($name));
        self::assertInstanceOf(SloDefinition::class, $definition, $name . ' is defined');

        return array_map(static fn (PointId $point): string => $point->toString(), $definition->points());
    }
}
