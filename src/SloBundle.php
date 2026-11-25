<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle;

use Msstc4Symfony\SloBundle\Application\Generation\AlertmanagerReceivers;
use Msstc4Symfony\SloBundle\Application\Generation\Generate\Exception\InvalidGenerationSettings;
use Msstc4Symfony\SloBundle\Application\Generation\Generate\GenerationConfig;
use Msstc4Symfony\SloBundle\Application\Port\ExpectedFailureClassifierInterface;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\CollectSloDefinitionsPass;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\RegisterSloMetricPass;
use Msstc4Symfony\SloBundle\DependencyInjection\ExpectedFailureClassifierSelector;
use Override;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\IntegerNodeDefinition;
use Symfony\Component\Config\Definition\Builder\StringNodeDefinition;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Normalised bundle configuration.
 *
 * Shapes: ObjectiveConfig is one objective of a configured operation; OperationConfig is one entry of `operations`
 * (an operation SLO); SloConfig is the whole processed `msstc4symfony_slo` tree.
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
 *         scheduled_tasks: array{required_percent: int<0, 100>},
 *     },
 *     operations: array<string, OperationConfig>,
 *     generation: array{
 *         selector: array{application: string|null, components: list<string>},
 *         output_dir: non-empty-string,
 *         grafana: array{datasource_uid: non-empty-string, folder: non-empty-string},
 *         prometheus: array{rules: bool, alerts: bool, no_events_alert_hours: positive-int|null, scrape_interval_seconds: positive-int},
 *         sloth: bool,
 *         alertmanager: array{enabled: bool, receivers: array{page: non-empty-string, ticket: non-empty-string}},
 *     },
 * }
 */
final class SloBundle extends AbstractBundle
{
    private const PARAMETER_PREFIX = 'msstc4symfony_slo.';

    private const OPERATIONS = 'operations';

    private const DEFAULT_REQUIRED_PERCENT = 95;

    protected string $extensionAlias = 'msstc4symfony_slo';

    #[Override]
    public function build(ContainerBuilder $container): void
    {
        // Priority -10: sees metric enums that bundles registered after this one append at priority 0.
        $container->addCompilerPass(new CollectSloDefinitionsPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, -10);
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
     * @param array<array-key, mixed> $config the processed tree, shaped like SloConfig; the parent signature takes a
     *                                        plain array, so the shape cannot narrow the parameter (contravariance)
     */
    #[Override]
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        foreach (self::flatten($config) as $path => $value) {
            $builder->setParameter(self::PARAMETER_PREFIX . $path, $value);
        }

        $container->import(__DIR__ . '/Resources/config/services.php');

        $classifier = ExpectedFailureClassifierSelector::select($builder);
        $builder->register($classifier, $classifier);
        $builder->setAlias(ExpectedFailureClassifierInterface::class, $classifier);
    }

    /**
     * One parameter per leaf: nested maps become dotted paths, lists and the operations map stay whole. Typed by value,
     * not by SloConfig: PHPStan has no recursive aliases, and every level of the tree passes through here.
     *
     * @param array<array-key, mixed> $config a (sub)tree of SloConfig
     *
     * @return array<string, array<array-key, mixed>|bool|float|int|string|null>
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
                ->arrayNode('scheduled_tasks')
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

    /**
     * @param non-empty-string $name
     * @param non-empty-string $default
     */
    private function receiverNode(string $name, string $default): StringNodeDefinition
    {
        $node = new StringNodeDefinition($name);
        $node
            ->cannotBeEmpty()
            ->defaultValue($default)
            ->validate()
                ->ifTrue(static fn (mixed $v): bool => !\is_string($v) || !AlertmanagerReceivers::isValidName($v))
                ->thenInvalid('the receiver name must match ' . AlertmanagerReceivers::NAME_PATTERN . ', %s given.')
            ->end()
        ;

        return $node;
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
                                ->thenInvalid('application must match ' . GenerationConfig::LABEL_VALUE . ': it becomes a label value in the generated PromQL.')
                            ->end()
                        ->end()
                        ->append(
                            $this->listNode('components')
                                ->scalarPrototype()
                                    ->validate()
                                        ->ifTrue(static fn (mixed $v): bool => !self::isLabelValue($v))
                                        ->thenInvalid('every component must match ' . GenerationConfig::LABEL_VALUE . ': it becomes a label value in the generated PromQL.')
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
                        ->integerNode('scrape_interval_seconds')->defaultValue(60)->min(1)->end()
                    ->end()
                ->end()
                ->booleanNode('sloth')->defaultFalse()->end()
                ->arrayNode('alertmanager')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->arrayNode('receivers')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->append($this->receiverNode('page', 'slo-page'))
                                ->append($this->receiverNode('ticket', 'slo-ticket'))
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue($this->alertmanagerWithoutAlerts(...))
                ->thenInvalid(InvalidGenerationSettings::ALERTMANAGER_REQUIRES_ALERTS)
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

    /**
     * @param array<array-key, mixed> $generation
     */
    private function alertmanagerWithoutAlerts(array $generation): bool
    {
        $alertmanager = $generation['alertmanager'] ?? null;
        $prometheus = $generation['prometheus'] ?? null;

        return \is_array($alertmanager) && \is_array($prometheus)
            && ($alertmanager['enabled'] ?? false) === true
            && ($prometheus['alerts'] ?? true) === false;
    }

    private static function isLabelValue(mixed $value): bool
    {
        return \is_string($value) && preg_match(GenerationConfig::LABEL_VALUE, $value) === 1;
    }
}
