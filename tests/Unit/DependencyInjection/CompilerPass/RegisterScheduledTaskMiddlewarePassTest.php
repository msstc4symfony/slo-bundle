<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\DependencyInjection\CompilerPass;

use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\RegisterScheduledTaskMiddlewarePass;
use Msstc4Symfony\SloBundle\Infrastructure\Scheduler\ScheduledTaskOutcomeMiddleware;
use Msstc4Symfony\SloBundle\Infrastructure\Scheduler\ScheduledTaskRunMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class RegisterScheduledTaskMiddlewarePassTest extends TestCase
{
    private const DEFAULT = [['id' => 'add_bus_name_stamp_middleware'], ['id' => 'send_message', 'arguments' => [false]], ['id' => 'handle_message', 'arguments' => [false]]];

    private const OUTCOME = ['id' => ScheduledTaskOutcomeMiddleware::class];

    private const RUN = ['id' => ScheduledTaskRunMiddleware::class];

    public function testPutsTheOutcomeFirstAndTheRunStartRightBeforeHandleMessageOnce(): void
    {
        $container = $this->container(true);

        (new RegisterScheduledTaskMiddlewarePass())->process($container);
        (new RegisterScheduledTaskMiddlewarePass())->process($container);

        self::assertSame([self::OUTCOME, self::DEFAULT[0], self::DEFAULT[1], self::RUN, self::DEFAULT[2]], $container->getParameter('messenger.bus.default.middleware'));
        self::assertSame([self::OUTCOME, ['id' => 'custom'], self::RUN, ['id' => 'messenger.middleware.handle_message']], $container->getParameter('long.bus.middleware'));
    }

    public function testLeavesABusWithoutHandleMessageAloneAndLogsIt(): void
    {
        $container = $this->container(true);

        (new RegisterScheduledTaskMiddlewarePass())->process($container);

        self::assertSame([['id' => 'custom']], $container->getParameter('raw.bus.middleware'));
        self::assertSame('not-a-list', $container->getParameter('odd.bus.middleware'));
        $logged = preg_grep('/Bus "raw\\.bus" has no handle_message middleware/', $container->getCompiler()->getLog());
        self::assertIsArray($logged);
        self::assertCount(1, $logged);
    }

    public function testDoesNothingWithoutTheMiddlewareServices(): void
    {
        $container = $this->container(false);

        (new RegisterScheduledTaskMiddlewarePass())->process($container);

        self::assertSame(self::DEFAULT, $container->getParameter('messenger.bus.default.middleware'));
    }

    private function container(bool $withMiddleware): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $buses = [
            'messenger.bus.default' => self::DEFAULT,
            'long.bus' => [['id' => 'custom'], ['id' => 'messenger.middleware.handle_message']],
            'raw.bus' => [['id' => 'custom']],
            'odd.bus' => 'not-a-list',
        ];
        foreach ($buses as $bus => $middleware) {
            $container->register($bus)->addTag('messenger.bus');
            $container->setParameter($bus . '.middleware', $middleware);
        }
        $container->register('untracked.bus')->addTag('messenger.bus');
        if ($withMiddleware) {
            $container->register(ScheduledTaskRunMiddleware::class);
            $container->register(ScheduledTaskOutcomeMiddleware::class);
        }

        return $container;
    }
}
