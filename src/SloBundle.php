<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle;

use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\CollectSloDefinitionsPass;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\RegisterSloMetricPass;
use Override;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Normalised bundle configuration.
 *
 * @phpstan-type ObjectiveConfig array{type: 'availability'|'latency'|'combined', target: float, threshold?: string, include_errors?: bool}
 * @phpstan-type OperationConfig array{objectives: non-empty-list<ObjectiveConfig>, window?: string, expected_failure_is_good?: bool}
 * @phpstan-type SloConfig array{
 *     defaults: array{
 *         window: string,
 *         http: array{bad_statuses: list<int|string>, ineligible_statuses: list<int|string>},
 *         logic: array{expected_failure_is_good: bool},
 *         messenger: array{count_attempts: bool, failure_transports: list<string>|null},
 *     },
 *     coverage: array{
 *         http_routes: array{required_percent: int<0, 100>, ignored_routes: list<string>},
 *         messenger_handlers: array{required_percent: int<0, 100>},
 *         logic_actions: array{required_percent: int<0, 100>},
 *     },
 *     operations: array<string, OperationConfig>,
 *     generation: array{
 *         selector: array{application: string|null, components: list<string>},
 *         output_dir: non-empty-string,
 *         grafana: array{datasource_uid: non-empty-string, folder: non-empty-string},
 *         prometheus: array{rules: bool, alerts: bool, no_events_alert_hours: positive-int|null},
 *         sloth: bool,
 *     },
 * }
 */
final class SloBundle extends AbstractBundle
{
    protected string $extensionAlias = 'msstc4symfony_slo';

    #[Override]
    public function build(ContainerBuilder $container): void
    {
        // Priority 0: after attribute autoconfiguration (100); bridges that tag points register at >= 10.
        $container->addCompilerPass(new CollectSloDefinitionsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
        $container->addCompilerPass(new RegisterSloMetricPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 0);
    }

    #[Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('defaults')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('window')->cannotBeEmpty()->defaultValue('30d')->end()
                        ->arrayNode('http')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->arrayNode('bad_statuses')->scalarPrototype()->end()->defaultValue(['5xx'])->end()
                                ->arrayNode('ineligible_statuses')->scalarPrototype()->end()->defaultValue([])->end()
                            ->end()
                        ->end()
                        ->arrayNode('logic')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('expected_failure_is_good')->defaultTrue()->end()
                            ->end()
                        ->end()
                        ->arrayNode('messenger')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('count_attempts')->defaultFalse()->end()
                                ->variableNode('failure_transports')
                                    ->defaultNull()
                                    ->validate()
                                        ->ifTrue(static fn (mixed $v): bool => $v !== null && (!\is_array($v) || !array_is_list($v) || array_filter($v, static fn (mixed $n): bool => !\is_string($n) || $n === '') !== []))
                                        ->thenInvalid('failure_transports must be null or a list of non-empty transport names.')
                                    ->end()
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('coverage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('http_routes')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('required_percent')->min(0)->max(100)->defaultValue(95)->end()
                                ->arrayNode('ignored_routes')->scalarPrototype()->end()->defaultValue([])->end()
                            ->end()
                        ->end()
                        ->arrayNode('messenger_handlers')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('required_percent')->min(0)->max(100)->defaultValue(95)->end()
                            ->end()
                        ->end()
                        ->arrayNode('logic_actions')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->integerNode('required_percent')->min(0)->max(100)->defaultValue(0)->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('operations')
                    ->useAttributeAsKey('name')
                    ->normalizeKeys(false)
                    ->defaultValue([])
                    ->arrayPrototype()
                        ->children()
                            ->arrayNode('objectives')
                                ->isRequired()
                                ->requiresAtLeastOneElement()
                                ->arrayPrototype()
                                    ->children()
                                        ->enumNode('type')->isRequired()->values(['availability', 'latency', 'combined'])->end()
                                        ->floatNode('target')->isRequired()->end()
                                        ->scalarNode('threshold')->cannotBeEmpty()->end()
                                        ->booleanNode('include_errors')->end()
                                    ->end()
                                ->end()
                            ->end()
                            ->scalarNode('window')->cannotBeEmpty()->end()
                            ->booleanNode('expected_failure_is_good')->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('generation')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->arrayNode('selector')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('application')->defaultNull()->end()
                                ->arrayNode('components')->scalarPrototype()->end()->defaultValue([])->end()
                            ->end()
                        ->end()
                        ->scalarNode('output_dir')->cannotBeEmpty()->defaultValue('%kernel.project_dir%/infrastructure/observability')->end()
                        ->arrayNode('grafana')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('datasource_uid')->cannotBeEmpty()->defaultValue('prometheus')->end()
                                ->scalarNode('folder')->cannotBeEmpty()->defaultValue('SLO')->end()
                            ->end()
                        ->end()
                        ->arrayNode('prometheus')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('rules')->defaultTrue()->end()
                                ->booleanNode('alerts')->defaultTrue()->end()
                                ->integerNode('no_events_alert_hours')->defaultNull()->min(1)->end()
                            ->end()
                        ->end()
                        ->booleanNode('sloth')->defaultFalse()->end()
                    ->end()
                ->end()
            ->end()
        ;
    }

    /**
     * @param SloConfig $config
     */
    #[Override] // @phpstan-ignore method.childParameterType, method.childParameterType
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $parameters = [
            'defaults.window' => $config['defaults']['window'],
            'defaults.http.bad_statuses' => $config['defaults']['http']['bad_statuses'],
            'defaults.http.ineligible_statuses' => $config['defaults']['http']['ineligible_statuses'],
            'defaults.logic.expected_failure_is_good' => $config['defaults']['logic']['expected_failure_is_good'],
            'defaults.messenger.count_attempts' => $config['defaults']['messenger']['count_attempts'],
            'defaults.messenger.failure_transports' => $config['defaults']['messenger']['failure_transports'],
            'coverage.http_routes.required_percent' => $config['coverage']['http_routes']['required_percent'],
            'coverage.http_routes.ignored_routes' => $config['coverage']['http_routes']['ignored_routes'],
            'coverage.messenger_handlers.required_percent' => $config['coverage']['messenger_handlers']['required_percent'],
            'coverage.logic_actions.required_percent' => $config['coverage']['logic_actions']['required_percent'],
            'operations' => $config['operations'],
            'generation.selector.application' => $config['generation']['selector']['application'],
            'generation.selector.components' => $config['generation']['selector']['components'],
            'generation.output_dir' => $config['generation']['output_dir'],
            'generation.grafana.datasource_uid' => $config['generation']['grafana']['datasource_uid'],
            'generation.grafana.folder' => $config['generation']['grafana']['folder'],
            'generation.prometheus.rules' => $config['generation']['prometheus']['rules'],
            'generation.prometheus.alerts' => $config['generation']['prometheus']['alerts'],
            'generation.prometheus.no_events_alert_hours' => $config['generation']['prometheus']['no_events_alert_hours'],
            'generation.sloth' => $config['generation']['sloth'],
        ];
        foreach ($parameters as $path => $value) {
            $builder->setParameter('msstc4symfony_slo.' . $path, $value);
        }

        $container->import(__DIR__ . '/Resources/config/services.php');
    }
}
