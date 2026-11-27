<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Kernel;

use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageInterface;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\CleanupTask;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\RedispatchedTask;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\SyncPartnersCommand;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\UnmonitoredTask;
use Override;
use PHPUnit\Framework\Attributes\RequiresMethod;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;

#[RequiresMethod(MessageBusInterface::class, 'dispatch')]
#[RequiresMethod(ServiceCallMessage::class, 'getServiceId')]
final class SchedulerSloKernelTest extends KernelTestCase
{
    private const CLEANUP = CleanupTask::class . '::__invoke';

    private const SYNC = 'command:app:sync-partners';

    private const RUN = '/^symfony_slo_scheduled_task_(started_total|finished_total|duration_seconds_count)\{application="test",component="test",task="((?:[^"\\\\]|\\\\.)+)"(?:,outcome="([a-z_]+)")?\} (\S+)$/m';

    #[Override]
    protected function setUp(): void
    {
        (new Filesystem())->remove(SchedulerSloTestKernel::cacheRoot());
        self::bootKernel();
    }

    #[Override]
    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        CleanupTask::$failing = false;
        SyncPartnersCommand::$failing = false;
        parent::tearDown();
        (new Filesystem())->remove(SchedulerSloTestKernel::cacheRoot());
    }

    public function testHandledTaskRunIsOneGoodEventAndOneSuccessfulRun(): void
    {
        $this->dispatch(new ServiceCallMessage(CleanupTask::class));
        $this->consume(SchedulerSloTestKernel::ASYNC, 1);

        self::assertSame(['cleanup.run|availability|good' => 1, 'cleanup.run|latency|good' => 1], $this->counts());
        self::assertSame([
            'duration_seconds_count|' . self::CLEANUP . '|success' => 1,
            'finished_total|' . self::CLEANUP . '|success' => 1,
            'started_total|' . self::CLEANUP => 1,
        ], $this->runs());
    }

    public function testTaskConsumedFromTheSchedulerTransportIsMeasured(): void
    {
        Clock::set(new MockClock('2026-11-25 10:00:00 UTC'));

        // The first run of the 60-second CleanupTask is due a minute after the worker starts; its sleeps advance the mock.
        $this->consume('scheduler_default', 1, 120);

        self::assertSame(['cleanup.run|availability|good' => 1, 'cleanup.run|latency|good' => 1], $this->counts());
        self::assertSame([
            'duration_seconds_count|' . self::CLEANUP . '|success' => 1,
            'finished_total|' . self::CLEANUP . '|success' => 1,
            'started_total|' . self::CLEANUP => 1,
        ], $this->runs());
    }

    /**
     * A task with "transports" reaches scheduler_<name> as a RedispatchMessage, which is not measured, and runs where
     * that sends it: the run counts once, on the consuming transport.
     */
    public function testTaskWithTransportsIsMeasuredOnceWhereItIsRedispatched(): void
    {
        Clock::set(new MockClock('2026-11-25 10:00:00 UTC'));
        $task = RedispatchedTask::class . '::__invoke';

        $this->consume('scheduler_redispatch', 1, 120);

        self::assertSame([], $this->runs());
        self::assertCount(1, $this->transport(SchedulerSloTestKernel::ASYNC)->get());

        $this->consume(SchedulerSloTestKernel::ASYNC, 1);

        self::assertSame(['redispatched.run|availability|good' => 1], $this->counts());
        self::assertSame([
            'duration_seconds_count|' . $task . '|success' => 1,
            'finished_total|' . $task . '|success' => 1,
            'started_total|' . $task => 1,
        ], $this->runs());
    }

    public function testRetriedAttemptIsNotAnSloEventAndFinishesAsRetried(): void
    {
        CleanupTask::$failing = true;

        $this->dispatch(new ServiceCallMessage(CleanupTask::class));
        $this->consume(SchedulerSloTestKernel::ASYNC, 2);

        self::assertSame([], $this->transport(SchedulerSloTestKernel::ASYNC)->get());
        self::assertCount(1, $this->transport(SchedulerSloTestKernel::FAILED)->get());
        self::assertSame(1, $this->counts()['cleanup.run|availability|bad'] ?? 0);
        self::assertArrayNotHasKey('cleanup.run|availability|good', $this->counts());
        self::assertSame([
            'duration_seconds_count|' . self::CLEANUP . '|error' => 1,
            'duration_seconds_count|' . self::CLEANUP . '|retried' => 1,
            'finished_total|' . self::CLEANUP . '|error' => 1,
            'finished_total|' . self::CLEANUP . '|retried' => 1,
            'started_total|' . self::CLEANUP => 2,
        ], $this->runs());
    }

    public function testReplayFromTheFailureTransportWritesRunMetricsButNoSloEvent(): void
    {
        CleanupTask::$failing = true;
        $this->dispatch(new ServiceCallMessage(CleanupTask::class));
        $this->consume(SchedulerSloTestKernel::ASYNC, 2);
        $before = $this->counts();
        CleanupTask::$failing = false;

        $retry = new CommandTester($this->application()->find('messenger:failed:retry'));
        $retry->execute(['--force' => true]);

        $retry->assertCommandIsSuccessful();
        self::assertSame($before, $this->counts());
        self::assertSame(3, $this->runs()['started_total|' . self::CLEANUP] ?? 0);
        self::assertSame(1, $this->runs()['finished_total|' . self::CLEANUP . '|success'] ?? 0);
    }

    public function testFailingCommandTaskIsAnErrorRunAndABadEvent(): void
    {
        SyncPartnersCommand::$failing = true;

        $this->dispatch(new RunCommandMessage((string) new ArrayInput(['command' => 'app:sync-partners', '--full' => true])));
        $this->consume(SchedulerSloTestKernel::ASYNC, 2);

        self::assertSame(['partners.sync|availability|bad' => 1], $this->counts());
        self::assertSame(1, $this->runs()['finished_total|' . self::SYNC . '|error'] ?? 0);
        self::assertSame(1, $this->runs()['finished_total|' . self::SYNC . '|retried'] ?? 0);
        self::assertSame(2, $this->runs()['started_total|' . self::SYNC] ?? 0);
    }

    public function testUnmonitoredTaskWritesNoRunMetrics(): void
    {
        $this->dispatch(new ServiceCallMessage(UnmonitoredTask::class));
        $this->consume(SchedulerSloTestKernel::ASYNC, 1);

        self::assertSame([], $this->transport(SchedulerSloTestKernel::ASYNC)->get());
        self::assertSame([], $this->runs());
        self::assertSame([], $this->counts());
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return SchedulerSloTestKernel::class;
    }

    private function dispatch(object $message): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        self::assertInstanceOf(MessageBusInterface::class, $bus);
        $bus->dispatch($message);
    }

    /**
     * @param positive-int $limit
     * @param positive-int $timeLimit
     */
    private function consume(string $transport, int $limit, int $timeLimit = 10): void
    {
        // Without --no-reset the worker resets the in-memory transport after each message and drops the retry.
        $consume = new CommandTester($this->application()->find('messenger:consume'));
        $consume->execute(['receivers' => [$transport], '--limit' => $limit, '--time-limit' => $timeLimit, '--no-reset' => null]);
        $consume->assertCommandIsSuccessful();
    }

    private function transport(string $name): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.' . $name);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function application(): Application
    {
        self::assertNotNull(self::$kernel);

        return new Application(self::$kernel);
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        return SloEventsExport::counts($this->export());
    }

    /**
     * @return array<string, int> "<sample>|<task>[|<outcome>]" => value, sorted by key
     */
    private function runs(): array
    {
        preg_match_all(self::RUN, $this->export(), $matches, PREG_SET_ORDER);
        $runs = [];
        foreach ($matches as $match) {
            $key = $match[1] . '|' . stripslashes($match[2]) . ($match[3] !== '' ? '|' . $match[3] : '');
            $runs[$key] = (int) $match[4];
        }
        ksort($runs);

        return $runs;
    }

    private function export(): string
    {
        $storage = self::getContainer()->get(MetricStorageInterface::class);
        self::assertInstanceOf(MetricStorageInterface::class, $storage);

        return $storage->export()->body;
    }
}
