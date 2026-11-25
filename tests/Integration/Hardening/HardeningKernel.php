<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Hardening;

use Monolog\Handler\TestHandler;
use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Msstc4Symfony\SloBundle\SloBundle;
use Msstc4Symfony\SloBundle\Test\Fixtures\Runtime\ChargeCard;
use Msstc4Symfony\SloBundle\Test\Fixtures\Runtime\ChargeCardHandler;
use Msstc4Symfony\SloBundle\Test\Fixtures\Runtime\CheckoutController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Scheduler\CleanupTask;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Symfony\Component\Scheduler\Messenger\ServiceCallMessage;

/**
 * HTTP, Messenger and Scheduler (when installed) and operation points on in-memory storage; the environment "failing_storage" makes the SLO writer's recorder throw,
 * "failing_storage_and_log" also makes every log record of the slo_bundle channel throw.
 */
final class HardeningKernel extends Kernel
{
    use MicroKernelTrait;

    public const FAILING_STORAGE = 'failing_storage';

    public const FAILING_STORAGE_AND_LOG = 'failing_storage_and_log';

    public const ASYNC = 'async';

    public const FAILED = 'failed';

    public static function cacheRoot(): string
    {
        return sys_get_temp_dir() . '/msstc4symfony-slo-bundle-hardening-test-' . getmypid();
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
        if ($this->environment === self::FAILING_STORAGE || $this->environment === self::FAILING_STORAGE_AND_LOG) {
            $container->addCompilerPass(new UseThrowingRecorderPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
        }
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $framework = [
            'secret' => 'test',
            'http_method_override' => false,
            'test' => true,
            'router' => ['utf8' => true],
            'php_errors' => ['log' => false],
        ];
        $messenger = interface_exists(MessageBusInterface::class);
        $scheduler = $messenger && class_exists(ServiceCallMessage::class);
        if ($messenger) {
            $framework['messenger'] = [
                'failure_transport' => self::FAILED,
                'transports' => [
                    self::ASYNC => ['dsn' => 'in-memory://', 'retry_strategy' => ['max_retries' => 0, 'delay' => 0]],
                    self::FAILED => 'in-memory://',
                ],
                'routing' => $scheduler ? [ChargeCard::class => self::ASYNC, ServiceCallMessage::class => self::ASYNC] : [ChargeCard::class => self::ASYNC],
            ];
        }
        if ($scheduler) {
            $framework['scheduler'] = true;
        }
        $container->extension('framework', $framework);
        $handlers = ['capture' => ['type' => 'service', 'id' => TestHandler::class]];
        if ($this->environment === self::FAILING_STORAGE_AND_LOG) {
            $handlers = ['broken' => ['type' => 'service', 'id' => ThrowingSloLogHandler::class, 'priority' => 10]] + $handlers;
        }
        $container->extension('monolog', ['handlers' => $handlers]);
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

        $services = $container->services();
        $services->set(TestHandler::class)->public();
        $services->set(ThrowingSloLogHandler::class)->public();
        $services->set(ThrowingRecorder::class)->public();
        $services->set(CheckoutController::class)->autowire()->autoconfigure()->tag('controller.service_arguments');
        if ($messenger) {
            $services->set(ChargeCardHandler::class)->autowire()->autoconfigure();
        }
        if ($scheduler) {
            $services->set(CleanupTask::class)->autowire()->autoconfigure();
            // FrameworkBundle drops "clock" when symfony/clock is only a dev dependency of the root package, as it is here.
            $services->set('clock', NativeClock::class);
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        $routes->add('checkout_page', '/checkout/{outcome}')->controller([CheckoutController::class, 'page'])->methods(['GET']);
        $routes->add('checkout_fragment', '/checkout-fragment')->controller([CheckoutController::class, 'withFragment'])->methods(['GET']);
        $routes->import('@MetricsBundle/Presentation/Controller/', 'attribute');
    }
}
