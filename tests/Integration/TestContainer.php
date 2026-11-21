<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration;

use Closure;
use LogicException;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;
use Msstc4Symfony\SloBundle\SloBundle;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\Routing\Router;

/**
 * Raw msstc4symfony_slo configuration used by the tests.
 *
 * @phpstan-type TestConfig array{
 *     defaults?: array{messenger?: array{failure_transports?: list<string>}},
 *     operations?: array<string, array{objectives: list<array{type: string, target: float|int, threshold?: string, include_errors?: bool}>, window?: string}>,
 * }
 */
final class TestContainer
{
    /**
     * @param TestConfig $config
     * @param (Closure(ContainerBuilder): void)|null $configure registers fixture services before compilation
     * @param bool $withMetricsBundle declares the parameters and services that MetricsBundle provides
     */
    public static function compile(array $config = [], ?Closure $configure = null, bool $withMetricsBundle = true): ContainerBuilder
    {
        $container = new ContainerBuilder(new ParameterBag([
            'kernel.environment' => 'test',
            'kernel.debug' => false,
            'kernel.cache_dir' => sys_get_temp_dir(),
            'kernel.project_dir' => sys_get_temp_dir(),
            // Symfony 7.4's PhpFileLoader reads it; 8.x does not.
            'kernel.build_dir' => sys_get_temp_dir(),
        ]));
        $bundle = new SloBundle();
        $extension = $bundle->getContainerExtension() ?? throw new LogicException('SloBundle has no extension.');
        $container->registerExtension($extension);
        $container->loadFromExtension($extension->getAlias(), $config);

        // Services of FrameworkBundle that the bundle's own services autowire; never instantiated by these tests.
        $container->setDefinition('router', new Definition(Router::class));
        $container->setDefinition(LoggerInterface::class, new Definition(NullLogger::class));

        if ($withMetricsBundle) {
            $container->setParameter('msstc4symfony_metrics.metric_enums', []);
            $container->setDefinition(MetricRecorderInterface::class, new Definition(NullMetricRecorder::class));
        }

        $bundle->build($container);
        if ($configure instanceof Closure) {
            $configure($container);
        }

        // The bundle's own services are private; keep them inspectable after compilation.
        $container->addCompilerPass(new MakeBundleServicesPublicPass(), PassConfig::TYPE_BEFORE_REMOVING);
        $container->compile(true);

        return $container;
    }
}
