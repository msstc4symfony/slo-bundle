<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Scheduler;

use Closure;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\Scheduler\ScheduledTaskMessage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresMethod;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Messenger\Message\RedispatchMessage;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;

#[RequiresMethod(ServiceCallMessage::class, 'getServiceId')]
final class ScheduledTaskMessageTest extends TestCase
{
    public function testServiceCallWithTheDefaultMethodResolves(): void
    {
        self::assertSame('app.cleanup::__invoke', $this->idOf(new ServiceCallMessage('app.cleanup')));
    }

    public function testServiceCallWithAMethodResolves(): void
    {
        self::assertSame('app.reports::daily', $this->idOf(new ServiceCallMessage('app.reports', 'daily')));
    }

    public function testCommandNameIsTheFirstArgumentOfTheInput(): void
    {
        self::assertSame('command:app:sync-partners', $this->idOf(new RunCommandMessage('app:sync-partners --full')));
    }

    public function testQuotedCommandNameFromArrayInputResolves(): void
    {
        $input = (string) new ArrayInput(['command' => 'app:sync-partners', '--full' => true]);

        self::assertStringStartsWith("'app:sync-partners'", $input);
        self::assertSame('command:app:sync-partners', $this->idOf(new RunCommandMessage($input)));
    }

    public function testUnterminatedQuoteIsTokenisedAsTheHandlerDoes(): void
    {
        // StringInput keeps a lone quote as a literal character instead of failing; the run then matches no SLO.
        self::assertSame("command:'unterminated", $this->idOf(new RunCommandMessage("'unterminated")));
    }

    /**
     * Messages are built lazily: providers run even when the class requirement skips the test.
     *
     * @return iterable<string, array{Closure(): object}>
     */
    public static function unresolvableProvider(): iterable
    {
        yield 'empty command input' => [static fn (): object => new RunCommandMessage('')];
        yield 'options only' => [static fn (): object => new RunCommandMessage('--full')];
        yield 'empty quoted command name' => [static fn (): object => new RunCommandMessage("'' --full")];
        yield 'empty service id' => [static fn (): object => new ServiceCallMessage('')];
        yield 'empty method' => [static fn (): object => new ServiceCallMessage('app.cleanup', '')];
        yield 'redispatch wrapper' => [static fn (): object => new RedispatchMessage(new ServiceCallMessage('x'), 'async')];
        yield 'any other message' => [static fn (): object => new stdClass()];
    }

    /**
     * @param Closure(): object $message
     */
    #[DataProvider('unresolvableProvider')]
    public function testUnresolvableMessageHasNoPoint(Closure $message): void
    {
        self::assertNull(ScheduledTaskMessage::pointOf($message()));
    }

    private function idOf(object $message): ?string
    {
        $point = ScheduledTaskMessage::pointOf($message);
        if ($point instanceof PointId) {
            self::assertSame(PointType::ScheduledTask, $point->type);
        }

        return $point?->id;
    }
}
