<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle;

use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\CollectSloDefinitionsPass;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\RegisterSloMetricPass;
use Override;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\IntegerNodeDefinition;
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
    private const LABEL_VALUE = '/^[A-Za-z0-9_.:-]{1,128}$/D';

    private const PARAMETER_PREFIX = 'msstc4symfony_slo.';

    private const OPERATIONS = 'operations';

    private const DEFAULT_REQUIRED_PERCENT = 95;

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
            ->append($this->defaultsNode())
            ->append($this->coverageNode())
            ->append($this->operationsNode())
            ->append($this->generationNode())
        ;
    }

    /**
     * @param array<mixed> $config the SloConfig tree, flattened into parameters as is
     */
    #[Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        foreach (self::flatten($config) as $path => $value) {
            $builder->setParameter(self::PARAMETER_PREFIX . $path, $value);
        }

        $container->import(__DIR__ . '/Resources/config/services.php');
    }

    /**
     * One parameter per leaf: nested maps become dotted paths, lists and the operations map stay whole.
     *
     * @param array<mixed> $config
     *
     * @return array<string, array<mixed>|bool|float|int|string|null>
     */
    private static function flatten(array $config, string $prefix = ''): array
    {
        $parameters = [];
        foreach ($config as $key => $value) {
            $path = $prefix . $key;
            if (\is_array($value) && !array_is_list($value) && $path !== self::OPERATIONS) {
                $parameters += self::flatten($value, $path . '.');
            } elseif (\is_array($value) || \is_scalar($value) || $value === null) {
                $parameters[$path] = $value;
            }
        }

        return $parameters;
    }

    private function defaultsNode(): ArrayNodeDefinition
    {
        return $this->node('defaults')
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('window')
                    ->cannotBeEmpty()
                    ->defaultValue(CollectSloDefinitionsPass::DEFAULT_WINDOW)
                    ->validate()->ifTrue(static fn (mixed $v): bool => !\is_string($v))->thenInvalid('window must be a string such as "30d", %s given.')->end()
                ->end()
                ->arrayNode('http')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append($this->statusesNode('bad_statuses', ['5xx']))
                        ->append($this->statusesNode('ineligible_statuses', []))
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
        ;
    }

    private function coverageNode(): ArrayNodeDefinition
    {
        return $this->node('coverage')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('http_routes')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append($this->requiredPercentNode(self::DEFAULT_REQUIRED_PERCENT))
                        ->append($this->listNode('ignored_routes')->scalarPrototype()->end()->defaultValue([]))
                    ->end()
                ->end()
                ->arrayNode('messenger_handlers')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append($this->requiredPercentNode(self::DEFAULT_REQUIRED_PERCENT))
                    ->end()
                ->end()
                ->arrayNode('logic_actions')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->append($this->requiredPercentNode(0))
                    ->end()
                ->end()
            ->end()
        ;
    }

    private function requiredPercentNode(int $default): IntegerNodeDefinition
    {
        return (new IntegerNodeDefinition('required_percent'))->min(0)->max(100)->defaultValue($default);
    }

    private function operationsNode(): ArrayNodeDefinition
    {
        return $this->node(self::OPERATIONS)
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
                            ->validate()
                                ->ifTrue(static fn (array $v): bool => $v['type'] !== 'availability' && !isset($v['threshold']))
                                ->thenInvalid('latency and combined objectives need a threshold: %s')
                            ->end()
                            ->validate()
                                ->ifTrue(static fn (array $v): bool => $v['type'] === 'availability' && isset($v['threshold']))
                                ->thenInvalid('availability objectives take no threshold: %s')
                            ->end()
                            ->validate()
                                ->ifTrue(static fn (array $v): bool => $v['type'] !== 'latency' && isset($v['include_errors']))
                                ->thenInvalid('include_errors applies to latency objectives only: %s')
                            ->end()
                        ->end()
                    ->end()
                    ->scalarNode('window')->cannotBeEmpty()->end()
                    ->booleanNode('expected_failure_is_good')->end()
                ->end()
            ->end()
        ;
    }

    private function generationNode(): ArrayNodeDefinition
    {
        return $this->node('generation')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('selector')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('application')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (mixed $v): bool => $v !== null && !self::isLabelValue($v))
                                ->thenInvalid('application must match ' . self::LABEL_VALUE . ': it becomes a label value in the generated PromQL.')
                            ->end()
                        ->end()
                        ->append(
                            $this->listNode('components')
                                ->scalarPrototype()
                                    ->validate()
                                        ->ifTrue(static fn (mixed $v): bool => !self::isLabelValue($v))
                                        ->thenInvalid('every component must match ' . self::LABEL_VALUE . ': it becomes a label value in the generated PromQL.')
                                    ->end()
                                ->end()
                                ->defaultValue([]),
                        )
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
        ;
    }

    /**
     * @param non-empty-string $name
     */
    private function node(string $name): ArrayNodeDefinition
    {
        return new ArrayNodeDefinition($name);
    }

    /**
     * A prototyped node keeps the keys of a YAML map, and flatten() would split such a map into one parameter per key.
     *
     * @param non-empty-string $name
     */
    private function listNode(string $name): ArrayNodeDefinition
    {
        $node = $this->node($name);
        $node->beforeNormalization()->ifArray()->then(array_values(...))->end();

        return $node;
    }

    /**
     * @param non-empty-string $name
     * @param list<int|string> $default
     */
    private function statusesNode(string $name, array $default): ArrayNodeDefinition
    {
        return $this->listNode($name)
            ->scalarPrototype()
                ->validate()
                    ->ifTrue(static fn (mixed $v): bool => !\is_int($v) && !\is_string($v))
                    ->thenInvalid('a status is a code (500), a class ("5xx") or a range ("500-503"), %s given.')
                ->end()
            ->end()
            ->defaultValue($default)
        ;
    }

    private static function isLabelValue(mixed $value): bool
    {
        return \is_string($value) && preg_match(self::LABEL_VALUE, $value) === 1;
    }
}
