<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Kernel;

use Msstc4Symfony\MetricsBundle\MetricsBundle;
use Msstc4Symfony\SloBundle\SloBundle;
use Msstc4Symfony\SloBundle\Test\Integration\MakeBundleServicesPublicPass;
use Override;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\MonologBundle\MonologBundle;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

/**
 * Real router and FrameworkBundle autoconfiguration for a chosen set of fixture controllers and routes.
 *
 * @phpstan-type RouteSpec array{name: non-empty-string, path: non-empty-string, controller: class-string, method?: non-empty-string, methods?: list<non-empty-string>}
 */
final class RoutedTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param list<class-string> $controllers registered as services, tagged as controllers
     * @param list<RouteSpec> $routes
     * @param list<string> $ignoredRoutes
     * @param array<string, mixed> $sloConfig msstc4symfony_slo configuration
     */
    public function __construct(
        private readonly array $controllers,
        private readonly array $routes,
        private readonly array $ignoredRoutes = [],
        private readonly array $sloConfig = [],
    ) {
        parent::__construct('test', false);
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

    public static function cacheRoot(): string
    {
        return sys_get_temp_dir() . '/msstc4symfony-slo-bundle-routed-test-' . getmypid();
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
        ]);
        $container->extension('monolog', ['handlers' => ['main' => ['type' => 'null']]]);
        $container->extension('msstc4symfony_metrics', [
            'storage' => ['dsn' => 'inmemory://'],
            'application_name' => 'test',
            'component_name' => 'test',
            'http' => ['ignored_routes' => $this->ignoredRoutes],
        ]);

        if ($this->sloConfig !== []) {
            $container->extension('msstc4symfony_slo', $this->sloConfig);
        }

        $services = $container->services()->defaults()->autowire()->autoconfigure();
        foreach ($this->controllers as $controller) {
            $services->set($controller)->tag('controller.service_arguments');
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        foreach ($this->routes as $spec) {
            $route = $routes->add($spec['name'], $spec['path'])->controller([$spec['controller'], $spec['method'] ?? '__invoke']);
            if (isset($spec['methods'])) {
                $route->methods($spec['methods']);
            }
        }
    }

    private function scenarioHash(): string
    {
        return substr(hash('sha256', serialize([$this->controllers, $this->routes, $this->ignoredRoutes, $this->sloConfig])), 0, 12);
    }
}
