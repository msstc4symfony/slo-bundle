<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Kernel;

use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Msstc4Symfony\SloBundle\SloBundle;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    // Per process: infection runs PHPUnit in parallel.
    public static function cacheRoot(): string
    {
        return sys_get_temp_dir() . '/msstc4symfony-slo-bundle-test-' . getmypid();
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
        ]);
        $container->extension('monolog', ['handlers' => ['main' => ['type' => 'null']]]);
        $container->extension('msstc4symfony_metrics', [
            'storage' => ['dsn' => 'inmemory://'],
            'application_name' => 'test',
            'component_name' => 'test',
        ]);
    }
}
