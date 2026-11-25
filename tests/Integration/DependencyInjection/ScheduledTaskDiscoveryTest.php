<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\DependencyInjection;

use Closure;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\CleanupTask;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\HousekeepingTasks;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\Invalid\NamelessSloCommand;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\NamelessCommand;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\ReportTasks;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\SyncPartnersCommand;
use Msstc4Symfony\SloBundle\Test\Integration\TestContainer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Hand-made scheduler.task tags: the shape FrameworkBundle's autoconfiguration gives them, without the Scheduler package.
 */
final class ScheduledTaskDiscoveryTest extends TestCase
{
    public function testServiceTaskPointUsesServiceIdAndMethod(): void
    {
        $catalog = $this->catalog(static function (ContainerBuilder $container): void {
            $container->register('app.cleanup', CleanupTask::class)
                ->addTag('scheduler.task', ['frequency' => 60, 'method' => null, 'arguments' => null, 'trigger' => 'every'])
            ;
        });

        $slo = $this->definition($catalog, 'cleanup.run');
        self::assertSame(['scheduled_task:app.cleanup::__invoke'], $this->points($slo));
        self::assertSame(PointType::ScheduledTask, $slo->pointType());
        self::assertSame([ObjectiveKind::Availability, ObjectiveKind::Latency], array_map(static fn (Objective $o): ObjectiveKind => $o->kind, $slo->objectives()));
        self::assertSame(['app.cleanup::__invoke'], $catalog->extras()->scheduledTasks);
    }

    public function testMethodSloAppliesOnlyToThatMethod(): void
    {
        $catalog = $this->catalog(static function (ContainerBuilder $container): void {
            $container->register('app.reports', ReportTasks::class)
                ->addTag('scheduler.task', ['expression' => '0 3 * * *', 'method' => 'daily', 'trigger' => 'cron'])
                ->addTag('scheduler.task', ['expression' => '0 4 * * 1', 'method' => 'weekly', 'trigger' => 'cron'])
            ;
        });

        self::assertSame(['scheduled_task:app.reports::daily'], $this->points($this->definition($catalog, 'report.daily')));
        self::assertSame(['app.reports::daily', 'app.reports::weekly'], $catalog->extras()->scheduledTasks);
        self::assertSame([], $catalog->forPoint(PointId::of(PointType::ScheduledTask, 'app.reports::weekly')));
    }

    public function testRepeatedTaskAttributesShareOnePointAndOneSlo(): void
    {
        $catalog = $this->catalog(static function (ContainerBuilder $container): void {
            $container->register('app.reports', ReportTasks::class)
                ->addTag('scheduler.task', ['frequency' => 60, 'method' => 'daily', 'trigger' => 'every'])
                ->addTag('scheduler.task', ['frequency' => 3600, 'method' => 'daily', 'trigger' => 'every'])
            ;
        });

        self::assertSame(['app.reports::daily'], $catalog->extras()->scheduledTasks);
        self::assertSame([], $catalog->conflicts());
        self::assertSame(['report.daily'], array_map(static fn (SloDefinition $d): string => $d->name()->value, $catalog->all()));
        self::assertSame(['scheduled_task:app.reports::daily'], $this->points($this->definition($catalog, 'report.daily')));
    }

    public function testClassLevelSloOverTwoTaskMethodsIsOneSloOnTwoPoints(): void
    {
        $catalog = $this->catalog(static function (ContainerBuilder $container): void {
            $container->register('app.housekeeping', HousekeepingTasks::class)
                ->addTag('scheduler.task', ['frequency' => 600, 'method' => 'purgeSessions', 'trigger' => 'every'])
                ->addTag('scheduler.task', ['frequency' => 3600, 'method' => 'rotateLogs', 'trigger' => 'every'])
            ;
        });

        self::assertSame([], $catalog->conflicts());
        self::assertSame(['housekeeping.run'], array_map(static fn (SloDefinition $d): string => $d->name()->value, $catalog->all()));
        self::assertSame(
            ['scheduled_task:app.housekeeping::purgeSessions', 'scheduled_task:app.housekeeping::rotateLogs'],
            $this->points($this->definition($catalog, 'housekeeping.run')),
        );
    }

    public function testCommandTaskPointUsesTheCommandName(): void
    {
        $catalog = $this->catalog(static function (ContainerBuilder $container): void {
            $container->register('app.sync_partners', SyncPartnersCommand::class)
                ->addTag('console.command')
                ->addTag('scheduler.task', ['frequency' => 3600, 'arguments' => ['--full' => true], 'trigger' => 'every'])
            ;
        });

        self::assertSame(['scheduled_task:command:app:sync-partners'], $this->points($this->definition($catalog, 'partners.sync')));
        self::assertSame(['command:app:sync-partners'], $catalog->extras()->scheduledTasks);
    }

    public function testUnresolvableCommandNameWithSloIsACompilationError(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('cannot resolve the command name');

        $this->catalog(static function (ContainerBuilder $container): void {
            $container->register('app.nameless', NamelessSloCommand::class)
                ->addTag('console.command')
                ->addTag('scheduler.task', ['frequency' => 60, 'trigger' => 'every'])
            ;
        });
    }

    public function testUnresolvableCommandNameWithoutSloIsSkipped(): void
    {
        $catalog = $this->catalog(static function (ContainerBuilder $container): void {
            $container->register('app.nameless', NamelessCommand::class)
                ->addTag('console.command')
                ->addTag('scheduler.task', ['frequency' => 60, 'trigger' => 'every'])
            ;
        });

        self::assertSame([], $catalog->extras()->scheduledTasks);
    }

    public function testMissingTaskMethodKeepsClassLevelSlo(): void
    {
        $catalog = $this->catalog(static function (ContainerBuilder $container): void {
            $container->register('app.cleanup', CleanupTask::class)
                ->addTag('scheduler.task', ['frequency' => 60, 'method' => 'nope', 'trigger' => 'every'])
            ;
        });

        self::assertSame(['scheduled_task:app.cleanup::nope'], $this->points($this->definition($catalog, 'cleanup.run')));
    }

    public function testNoSchedulerTagsDiscoversNothing(): void
    {
        $catalog = $this->catalog(static function (ContainerBuilder $container): void {
            $container->register('app.cleanup', CleanupTask::class);
        });

        self::assertSame([], $catalog->extras()->scheduledTasks);
        self::assertSame([], $catalog->all());
    }

    /**
     * @param Closure(ContainerBuilder): void $configure
     */
    private function catalog(Closure $configure): CompiledSloCatalog
    {
        $catalog = TestContainer::compile([], $configure)->get(CompiledSloCatalog::class);
        self::assertInstanceOf(CompiledSloCatalog::class, $catalog);

        return $catalog;
    }

    /**
     * @param non-empty-string $name
     */
    private function definition(CompiledSloCatalog $catalog, string $name): SloDefinition
    {
        $definition = $catalog->get(SloName::fromString($name));
        self::assertInstanceOf(SloDefinition::class, $definition, $name . ' is defined');

        return $definition;
    }

    /**
     * @return list<non-empty-string>
     */
    private function points(SloDefinition $definition): array
    {
        return array_map(static fn (PointId $point): string => $point->toString(), $definition->points());
    }
}
