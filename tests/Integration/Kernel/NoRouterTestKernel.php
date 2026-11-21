<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Kernel;

use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Msstc4Symfony\SloBundle\SloBundle;
use Msstc4Symfony\SloBundle\Test\Fixtures\Runtime\ChargeCard;
use Msstc4Symfony\SloBundle\Test\Fixtures\Runtime\ChargeCardHandler;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * framework.router.enabled: false (a worker-only or console-only application), Messenger only when installed.
 */
final class NoRouterTestKernel extends Kernel
{
    use MicroKernelTrait;

    public const ASYNC = 'async';

    public static function cacheRoot(): string
    {
        return sys_get_temp_dir() . '/msstc4symfony-slo-bundle-no-router-test-' . getmypid();
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
        $framework = [
            'secret' => 'test',
            'http_method_override' => false,
            'test' => true,
            'router' => ['enabled' => false],
            'php_errors' => ['log' => false],
        ];
        if (interface_exists(MessageBusInterface::class)) {
            $framework['messenger'] = [
                'transports' => [self::ASYNC => 'in-memory://'],
                'routing' => [ChargeCard::class => self::ASYNC],
            ];
        }
        $container->extension('framework', $framework);
        $container->extension('monolog', ['handlers' => ['main' => ['type' => 'null']]]);
        $container->extension('msstc4symfony_metrics', [
            'storage' => ['dsn' => 'inmemory://'],
            'application_name' => 'test',
            'component_name' => 'test',
        ]);
        $container->extension('msstc4symfony_slo', [
            'operations' => [
                'payment.validation' => ['objectives' => [['type' => 'availability', 'target' => 99.9]]],
            ],
        ]);

        if (interface_exists(MessageBusInterface::class)) {
            $container->services()->defaults()->autowire()->autoconfigure()->set(ChargeCardHandler::class);
        }
    }
}
