<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Kernel;

use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Msstc4Symfony\SloBundle\SloBundle;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\InvokableController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Handler\ProcessPaymentHandler;
use Msstc4Symfony\SloBundle\Test\Fixtures\Handler\ReceiptHandler;
use Msstc4Symfony\SloBundle\Test\Fixtures\Message\ProcessPayment;
use Msstc4Symfony\SloBundle\Test\Integration\MakeBundleServicesPublicPass;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Real FrameworkBundle Messenger config: routing, failure transport and attribute autoconfiguration.
 */
final class MessengerTestKernel extends Kernel
{
    use MicroKernelTrait;

    public static function cacheRoot(): string
    {
        return sys_get_temp_dir() . '/msstc4symfony-slo-bundle-messenger-test-' . getmypid();
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
            'messenger' => [
                'failure_transport' => 'failed',
                'transports' => [
                    'async' => 'in-memory://',
                    'priority' => ['dsn' => 'in-memory://', 'failure_transport' => 'dead'],
                    'failed' => 'in-memory://',
                    'dead' => 'in-memory://',
                ],
                'routing' => [ProcessPayment::class => 'async'],
            ],
        ]);
        $container->extension('monolog', ['handlers' => ['main' => ['type' => 'null']]]);
        $container->extension('msstc4symfony_metrics', [
            'storage' => ['dsn' => 'inmemory://'],
            'application_name' => 'test',
            'component_name' => 'test',
        ]);

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set(ProcessPaymentHandler::class);
        $services->set(ReceiptHandler::class)->tag('messenger.message_handler', ['method' => 'onReceipt']);
        $services->set(InvokableController::class);
    }
}
