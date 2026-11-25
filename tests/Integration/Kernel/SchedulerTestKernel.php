<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Kernel;

use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Msstc4Symfony\SloBundle\SloBundle;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\CleanupTask;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\ReportTasks;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\SyncPartnersCommand;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\UnmonitoredTask;
use Msstc4Symfony\SloBundle\Test\Integration\MakeBundleServicesPublicPass;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * FrameworkBundle Scheduler with #[AsPeriodicTask] / #[AsCronTask] autoconfiguration of the Scheduler fixtures.
 */
final class SchedulerTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $sloConfig msstc4symfony_slo configuration
     */
    public function __construct(private readonly array $sloConfig = [])
    {
        parent::__construct('test', false);
    }

    public static function cacheRoot(): string
    {
        return sys_get_temp_dir() . '/msstc4symfony-slo-bundle-scheduler-test-' . getmypid();
    }

    #[Override]
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new MonologBundle(), new MetricsBundle(), new SloBundle()];
    }

    #[Override]
    public function getCacheDir(): string
    {
        return self::cacheRoot() . '/' . $this->scenarioHash() . '/cache';
    }

    #[Override]
    public function getLogDir(): string
    {
        return self::cacheRoot() . '/' . $this->scenarioHash() . '/log';
    }

    #[Override]
    protected function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new MakeBundleServicesPublicPass(), PassConfig::TYPE_BEFORE_REMOVING);
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'http_method_override' => false,
            'test' => true,
            'router' => ['utf8' => true],
            'php_errors' => ['log' => false],
            'messenger' => ['transports' => ['async' => 'in-memory://']],
            'scheduler' => true,
        ]);
        $container->extension('monolog', ['handlers' => ['main' => ['type' => 'null']]]);
        $container->extension('msstc4symfony_metrics', [
            'storage' => ['dsn' => 'inmemory://'],
            'application_name' => 'test',
            'component_name' => 'test',
        ]);
        if ($this->sloConfig !== []) {
            $container->extension('msstc4symfony_slo', $this->sloConfig);
        }

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(CleanupTask::class);
        $services->set(ReportTasks::class);
        $services->set(SyncPartnersCommand::class);
        $services->set(UnmonitoredTask::class);
        // FrameworkBundle drops "clock" when symfony/clock is only a dev dependency of the root package, as it is here.
        $services->set('clock', NativeClock::class);
    }

    private function scenarioHash(): string
    {
        return substr(hash('sha256', serialize($this->sloConfig)), 0, 12);
    }
}
