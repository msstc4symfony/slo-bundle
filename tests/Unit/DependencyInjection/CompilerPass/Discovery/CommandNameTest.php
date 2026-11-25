<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\DependencyInjection\CompilerPass\Discovery;

use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery\CommandName;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\CleanupTask;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\LegacyNamedCommand;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\NamelessCommand;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\SyncPartnersCommand;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class CommandNameTest extends TestCase
{
    public function testTagAttributeWinsAndAliasesAreDropped(): void
    {
        $container = $this->container(SyncPartnersCommand::class, ['command' => 'app:a|app:alias']);

        self::assertTrue(CommandName::isCommandTask($container, 'task'));
        self::assertSame('app:a', CommandName::resolve($container, 'task'));
    }

    public function testHiddenCommandUsesTheNameAfterTheLeadingPipe(): void
    {
        self::assertSame('app:hidden', CommandName::resolve($this->container(NamelessCommand::class, ['command' => '|app:hidden']), 'task'));
    }

    public function testAsCommandNameIsUsedWithoutTagAttribute(): void
    {
        self::assertSame('app:sync-partners', CommandName::resolve($this->container(SyncPartnersCommand::class, []), 'task'));
    }

    public function testNoNameAnywhereIsNull(): void
    {
        $container = $this->container(NamelessCommand::class, []);

        self::assertTrue(CommandName::isCommandTask($container, 'task'));
        self::assertNull(CommandName::resolve($container, 'task'));
    }

    public function testServiceWithoutCommandTagIsNotACommandTask(): void
    {
        $container = new ContainerBuilder();
        $container->register('task', CleanupTask::class);

        self::assertFalse(CommandName::isCommandTask($container, 'task'));
        self::assertNull(CommandName::resolve($container, 'task'));
    }

    public function testMethodLevelCommandTagMatchesOnlyItsTaskMethod(): void
    {
        $container = $this->container(NamelessCommand::class, ['command' => 'app:report', 'method' => 'report']);

        self::assertTrue(CommandName::isCommandTask($container, 'task', 'report'));
        self::assertSame('app:report', CommandName::resolve($container, 'task', 'report'));
        self::assertFalse(CommandName::isCommandTask($container, 'task'));
        self::assertNull(CommandName::resolve($container, 'task'));
    }

    #[RequiresMethod(Command::class, 'getDefaultName')]
    public function testOverriddenDefaultNameIsTheLastResort(): void
    {
        self::assertSame('app:legacy', CommandName::resolve($this->container(LegacyNamedCommand::class, []), 'task'));
    }

    /**
     * @param class-string $class
     * @param array<string, string> $tag
     */
    private function container(string $class, array $tag): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register('task', $class)->addTag('console.command', $tag);

        return $container;
    }
}
