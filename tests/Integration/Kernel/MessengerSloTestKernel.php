<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Kernel;

use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Msstc4Symfony\SloBundle\SloBundle;
use Msstc4Symfony\SloBundle\Test\Fixtures\Runtime\ChargeCard;
use Msstc4Symfony\SloBundle\Test\Fixtures\Runtime\ChargeCardHandler;
use Msstc4Symfony\SloBundle\Test\Fixtures\Runtime\WriteAuditHandler;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Worker path of metrics-bundle with in-memory transports: two retries without delay, then the failure transport.
 */
final class MessengerSloTestKernel extends Kernel
{
    use MicroKernelTrait;

    public const ASYNC = 'async';

    public const FAILED = 'failed';

    public static function cacheRoot(): string
    {
        return sys_get_temp_dir() . '/msstc4symfony-slo-bundle-messenger-slo-test-' . getmypid();
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
                    self::ASYNC => ['dsn' => 'in-memory://', 'retry_strategy' => ['max_retries' => 2, 'delay' => 0]],
                    self::FAILED => 'in-memory://',
                ],
                'routing' => [ChargeCard::class => self::ASYNC],
            ],
        ]);
        $container->extension('monolog', ['handlers' => ['main' => ['type' => 'null']]]);
        $container->extension('msstc4symfony_metrics', [
            'storage' => ['dsn' => 'inmemory://'],
            'application_name' => 'test',
            'component_name' => 'test',
        ]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(ChargeCardHandler::class);
        $services->set(WriteAuditHandler::class);
    }
}
