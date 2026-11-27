<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Kernel;

use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Msstc4Symfony\SloBundle\SloBundle;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\CleanupTask;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\FailAfterHandlingMiddleware;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\RedispatchedTask;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\RefusingWorkerListener;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\SyncPartnersCommand;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\SyncTask;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\UnmonitoredTask;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;

/**
 * Scheduler task messages consumed by a worker from in-memory transports: one retry without delay, then the failure transport.
 * Only periodic tasks: the cron trigger needs dragonmantank/cron-expression, absent here, to build scheduler_default.
 */
final class SchedulerSloTestKernel extends Kernel
{
    use MicroKernelTrait;

    public const ASYNC = 'async';

    public const FAILED = 'failed';

    public const SYNC = 'sync';

    public static function cacheRoot(): string
    {
        return sys_get_temp_dir() . '/msstc4symfony-slo-bundle-scheduler-slo-test-' . getmypid();
    }

    #[Override]
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new MonologBundle(), new MetricsBundle(), new SloBundle()];
    }

    #[Override]
    public function getCacheDir(): string
    {
        return self::cacheRoot() . '/cache/' . $this->environment;
    }

    #[Override]
    public function getLogDir(): string
    {
        return self::cacheRoot() . '/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'http_method_override' => false,
            'test' => true,
            'router' => ['utf8' => true],
            'php_errors' => ['log' => false],
            'messenger' => [
                'failure_transport' => self::FAILED,
                'transports' => [
                    self::ASYNC => ['dsn' => 'in-memory://', 'retry_strategy' => ['max_retries' => 1, 'delay' => 0]],
                    self::FAILED => 'in-memory://',
                    self::SYNC => 'sync://',
                ],
                'routing' => [ServiceCallMessage::class => self::ASYNC, RunCommandMessage::class => self::ASYNC],
                'default_bus' => 'messenger.bus.default',
                'buses' => ['messenger.bus.default' => ['middleware' => [FailAfterHandlingMiddleware::class]]],
            ],
            'scheduler' => true,
        ]);
        $container->extension('monolog', ['handlers' => ['main' => ['type' => 'null']]]);
        $container->extension('msstc4symfony_metrics', [
            'storage' => ['dsn' => 'inmemory://'],
            'application_name' => 'test',
            'component_name' => 'test',
        ]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(CleanupTask::class);
        $services->set(RedispatchedTask::class);
        $services->set(SyncTask::class);
        $services->set(FailAfterHandlingMiddleware::class);
        $services->set(RefusingWorkerListener::class);
        $services->set(SyncPartnersCommand::class);
        $services->set(UnmonitoredTask::class);
        // FrameworkBundle drops "clock" when symfony/clock is only a dev dependency of the root package, as it is here.
        // The global clock lets a test drive the scheduler and the worker (which uses it too) with a MockClock.
        $services->set('clock', Clock::class)->autowire(false);
    }
}
