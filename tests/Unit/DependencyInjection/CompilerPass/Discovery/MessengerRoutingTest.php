<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\DependencyInjection\CompilerPass\Discovery;

use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery\MessengerRouting;
use Msstc4Symfony\SloBundle\Test\Fixtures\Message\AuditNotice;
use Msstc4Symfony\SloBundle\Test\Fixtures\Message\ProcessPayment;
use Msstc4Symfony\SloBundle\Test\Fixtures\Message\RefundPayment;
use Msstc4Symfony\SloBundle\Test\Fixtures\Message\SendReceipt;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\Attribute\AsMessage;
use Symfony\Component\Messenger\Message\RedispatchMessage;

final class MessengerRoutingTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, list<string>>, array<string, array{dsn: string, failure?: bool, scheduler?: bool}>, string, non-empty-string|null, bool}>
     */
    public static function provideClassification(): iterable
    {
        $async = ['async' => ['dsn' => 'amqp://localhost']];
        $sync = ['sync' => ['dsn' => 'sync://']];
        $failed = ['failed' => ['dsn' => 'doctrine://default', 'failure' => true]];
        $inbox = ['inbox' => ['dsn' => 'amqp://inbox']];
        $scheduler = ['scheduler_default' => ['dsn' => 'schedule://default', 'scheduler' => true]];

        yield 'routed to a worker transport' => [[ProcessPayment::class => ['async']], $async, ProcessPayment::class, null, true];
        yield 'routed by transport service id' => [[ProcessPayment::class => ['messenger.transport.async']], $async, ProcessPayment::class, null, true];
        yield 'routed to a sync transport only' => [[ProcessPayment::class => ['sync']], $sync + $async, ProcessPayment::class, null, false];
        yield 'routed to sync and a worker transport' => [[ProcessPayment::class => ['sync', 'async']], $sync + $async, ProcessPayment::class, null, true];
        yield 'routed to a custom sender' => [[ProcessPayment::class => ['app.sender']], [], ProcessPayment::class, null, true];
        yield 'bound to a worker transport' => [[ProcessPayment::class => ['async']], $async + ['inbox' => ['dsn' => 'amqp://inbox']], SendReceipt::class, 'inbox', true];
        yield 'bound to a sync transport' => [[], $sync, SendReceipt::class, 'sync', false];
        yield 'unrouted in a consumer-only app' => [[], $async + $failed, ProcessPayment::class, null, true];
        yield 'unrouted next to a receive-only transport' => [[SendReceipt::class => ['async']], $async + ['inbox' => ['dsn' => 'amqp://inbox']], ProcessPayment::class, null, true];
        yield 'unrouted where every transport is a routing target' => [[SendReceipt::class => ['async']], $async + $failed, ProcessPayment::class, null, false];
        yield 'unrouted with sync and failure transports only' => [[], $sync + $failed, ProcessPayment::class, null, false];
        yield 'unrouted without transports' => [[], [], ProcessPayment::class, null, false];
        yield 'unrouted framework message in a consumer-only app' => [[], $async, RedispatchMessage::class, null, false];
        yield 'empty sender list is not routing' => [[ProcessPayment::class => []], $sync, ProcessPayment::class, null, false];
        yield 'bound to an unknown transport' => [[], $sync, SendReceipt::class, 'elsewhere', true];
        yield 'bound to a failure transport' => [[], $async + $failed, SendReceipt::class, 'failed', false];
        yield 'routed to an env-placeholder transport' => [[ProcessPayment::class => ['env']], ['env' => ['dsn' => '%env(MESSENGER_DSN)%']], ProcessPayment::class, null, true];
        yield 'unrouted next to a receive-only env-placeholder transport' => [[], ['env' => ['dsn' => '%env(MESSENGER_DSN)%']], ProcessPayment::class, null, true];
        yield 'routed by namespace wildcard next to a receive-only transport' => [['Msstc4Symfony\\SloBundle\\Test\\Fixtures\\Message\\*' => ['async']], $async + $inbox, ProcessPayment::class, null, true];
        yield 'routed by "*" to sync only next to a receive-only transport' => [['*' => ['sync']], $sync + $inbox, ProcessPayment::class, null, false];
        yield 'unrouted while a namespace wildcard targets the only worker transport' => [['Msstc4Symfony\\SloBundle\\Test\\Fixtures\\Message\\Legacy\\*' => ['async']], $async, ProcessPayment::class, null, false];
        yield 'unrouted next to a scheduler transport' => [[SendReceipt::class => ['async']], $async + $scheduler, ProcessPayment::class, null, false];
        yield 'bound to a scheduler transport' => [[], $scheduler, ProcessPayment::class, 'scheduler_default', true];
        yield 'shared transport: unrouted message consumed from a routing target stays sync' => [[SendReceipt::class => ['async']], $async, ProcessPayment::class, null, false];
        yield 'shared transport: fromTransport makes it worker-consumed' => [[SendReceipt::class => ['async']], $async, ProcessPayment::class, 'async', true];
    }

    /**
     * @param array<string, list<string>> $routing
     * @param array<string, array{dsn: string, failure?: bool, scheduler?: bool}> $transports
     * @param non-empty-string|null $fromTransport
     */
    #[DataProvider('provideClassification')]
    public function testClassification(array $routing, array $transports, string $message, ?string $fromTransport, bool $async): void
    {
        $classifier = MessengerRouting::fromContainer($this->container($routing, $transports), [], [$message]);

        self::assertSame($async, $classifier->isAsync($message, $fromTransport));
    }

    public function testWithoutMessengerNothingIsAsync(): void
    {
        $container = new ContainerBuilder();
        $this->transport($container, 'async', 'amqp://localhost');

        self::assertFalse(MessengerRouting::fromContainer($container, [], [ProcessPayment::class])->isAsync(ProcessPayment::class, 'async'));
    }

    public function testConfiguredFailureTransportIsNotReceiveOnly(): void
    {
        $container = $this->container([SendReceipt::class => ['async']], ['async' => ['dsn' => 'amqp://localhost'], 'manual' => ['dsn' => 'amqp://manual']]);

        self::assertTrue(MessengerRouting::fromContainer($container, [], [ProcessPayment::class])->isAsync(ProcessPayment::class));
        self::assertFalse(MessengerRouting::fromContainer($container, ['manual'], [ProcessPayment::class])->isAsync(ProcessPayment::class));
    }

    #[RequiresMethod(AsMessage::class, '__construct')]
    public function testAsMessageTransportOfAHandledMessageIsARoutingTarget(): void
    {
        $container = $this->container([], ['async' => ['dsn' => 'amqp://localhost'], 'sync' => ['dsn' => 'sync://']]);
        $routing = MessengerRouting::fromContainer($container, [], [RefundPayment::class, ProcessPayment::class]);

        self::assertTrue($routing->isAsync(RefundPayment::class));
        self::assertFalse($routing->isAsync(ProcessPayment::class), 'the only worker transport is the #[AsMessage] target of a handled message');
    }

    #[RequiresMethod(AsMessage::class, '__construct')]
    public function testAsMessageToSyncOnlyIsSync(): void
    {
        $container = $this->container([], ['sync' => ['dsn' => 'sync://'], 'inbox' => ['dsn' => 'amqp://inbox']]);

        self::assertFalse(MessengerRouting::fromContainer($container, [], [AuditNotice::class])->isAsync(AuditNotice::class));
    }

    /**
     * @param array<string, list<string>> $routing
     * @param array<string, array{dsn: string, failure?: bool, scheduler?: bool}> $transports
     */
    private function container(array $routing, array $transports): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(MessengerRouting::SENDERS_LOCATOR, 'stdClass')->setArguments([$routing, null]);
        foreach ($transports as $name => $transport) {
            if ($transport['scheduler'] ?? false) {
                // Registered like AddScheduleMessengerPass does: alias only, no is_consumable/is_failure_transport.
                $container->register('messenger.transport.' . $name, 'stdClass')->setArguments([$transport['dsn'], [], null])->addTag('messenger.receiver', ['alias' => $name]);
                continue;
            }
            $this->transport($container, $name, $transport['dsn'], $transport['failure'] ?? false);
        }

        return $container;
    }

    private function transport(ContainerBuilder $container, string $name, string $dsn, bool $failure = false): void
    {
        $tag = ['alias' => $name, 'is_failure_transport' => $failure];
        if (str_starts_with($dsn, 'sync://')) {
            $tag['is_consumable'] = false;
        }
        $container->register('messenger.transport.' . $name, 'stdClass')->setArguments([$dsn, [], null])->addTag('messenger.receiver', $tag);
    }
}
