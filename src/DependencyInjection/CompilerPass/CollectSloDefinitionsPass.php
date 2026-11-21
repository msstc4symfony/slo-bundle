<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass;

use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogDump;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Discovery\DefinitionFactory;
use Msstc4Symfony\SloBundle\Application\Discovery\DiscoveryErrors;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery\AttributeReader;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery\ControllerReader;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery\HandlerReader;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery\MessengerRouting;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery\MetricsParameters;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery\OperationReader;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery\TaggedPointReader;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Override;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Builds the SLO catalog from controllers, Messenger handlers, tagged points and configured operations (spec 5.2).
 * Runs TYPE_BEFORE_OPTIMIZATION, priority 0: after attribute autoconfiguration has added the tags.
 */
final class CollectSloDefinitionsPass implements CompilerPassInterface
{
    private const PARAMETER_PREFIX = 'msstc4symfony_slo.';

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(CompiledSloCatalog::class)) {
            return;
        }

        $errors = new DiscoveryErrors();
        $assembler = new CatalogAssembler();
        $attributes = new AttributeReader($assembler, $errors);

        $controllerReader = new ControllerReader($attributes);
        $controllers = $controllerReader->read($container);
        [$handlers, $warnings] = (new HandlerReader($attributes, $errors))->read($container, MessengerRouting::fromContainer($container));
        $taggedPoints = (new TaggedPointReader($attributes, $errors))->read($container);
        $operations = (new OperationReader($assembler))->read($this->parameter($container, 'operations'));

        $extras = new CatalogExtras(
            $controllers,
            $handlers,
            $taggedPoints,
            $operations,
            MessengerRouting::failureTransports($container, $this->parameter($container, 'defaults.messenger.failure_transports')),
            MetricsParameters::buckets($container),
            MetricsParameters::httpIgnoredRoutes($container),
            $warnings,
            $controllerReader->serviceIds($container),
        );
        $contents = $assembler->assemble($this->factory($container), $errors, $extras);

        if (!$errors->isEmpty()) {
            throw new InvalidConfigurationException($errors->summary());
        }

        $container->getDefinition(CompiledSloCatalog::class)->setArgument('$catalog', CatalogDump::toArray($contents));
    }

    private function factory(ContainerBuilder $container): DefinitionFactory
    {
        $window = $this->parameter($container, 'defaults.window');
        $expectedFailureIsGood = $this->parameter($container, 'defaults.logic.expected_failure_is_good');
        $countAttempts = $this->parameter($container, 'defaults.messenger.count_attempts');

        return new DefinitionFactory(
            \is_string($window) ? $window : '30d',
            $this->statuses($this->parameter($container, 'defaults.http.bad_statuses')),
            $this->statuses($this->parameter($container, 'defaults.http.ineligible_statuses')),
            $expectedFailureIsGood !== false,
            $countAttempts === true,
        );
    }

    /**
     * @return list<int|string>
     */
    private function statuses(mixed $value): array
    {
        return array_values(array_filter(\is_array($value) ? $value : [], static fn (mixed $item): bool => \is_int($item) || \is_string($item)));
    }

    private function parameter(ContainerBuilder $container, string $path): mixed
    {
        $name = self::PARAMETER_PREFIX . $path;

        return $container->hasParameter($name) ? $container->getParameterBag()->resolveValue($container->getParameter($name)) : null;
    }
}
