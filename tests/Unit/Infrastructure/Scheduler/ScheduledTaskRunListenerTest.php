<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Scheduler;

use Msstc4Symfony\SloBundle\Infrastructure\Scheduler\ScheduledTaskRunListener;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;

#[RequiresMethod(ServiceCallMessage::class, 'getServiceId')]
final class ScheduledTaskRunListenerTest extends TestCase
{
    public function testReportsOutcomesAfterTheRetryListenerDecided(): void
    {
        $listeners = [];
        foreach ((new ReflectionClass(ScheduledTaskRunListener::class))->getMethods() as $method) {
            foreach ($method->getAttributes(AsEventListener::class) as $attribute) {
                $listener = $attribute->newInstance();
                $listeners[$listener->event ?? ''] = [$method->getName(), $listener->priority];
            }
        }

        self::assertSame([
            WorkerMessageHandledEvent::class => ['onHandled', 0],
            WorkerMessageFailedEvent::class => ['onFailed', 0],
        ], $listeners);
    }
}
