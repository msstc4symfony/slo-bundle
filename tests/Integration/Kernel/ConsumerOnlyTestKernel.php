<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Kernel;

use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Msstc4Symfony\SloBundle\SloBundle;
use Msstc4Symfony\SloBundle\Test\Fixtures\Consumer\ArchiveInvoiceHandler;
use Msstc4Symfony\SloBundle\Test\Fixtures\Consumer\SettleInvoiceHandler;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * A consumer service: it defines the transport its worker reads, but the producing service owns the routing.
 */
final class ConsumerOnlyTestKernel extends Kernel
{
    use MicroKernelTrait;

    public const INBOX = 'inbox';

    public const FAILED = 'failed';

    public static function cacheRoot(): string
    {
        return sys_get_temp_dir() . '/msstc4symfony-slo-bundle-consumer-only-test-' . getmypid();
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
                    self::INBOX => ['dsn' => 'in-memory://', 'retry_strategy' => ['max_retries' => 0, 'delay' => 0]],
                    self::FAILED => 'in-memory://',
                    'sync' => 'sync://',
                ],
            ],
        ]);
        $container->extension('monolog', ['handlers' => ['main' => ['type' => 'null']]]);
        $container->extension('msstc4symfony_metrics', [
            'storage' => ['dsn' => 'inmemory://'],
            'application_name' => 'test',
            'component_name' => 'test',
        ]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(SettleInvoiceHandler::class);
        $services->set(ArchiveInvoiceHandler::class);
    }
}
