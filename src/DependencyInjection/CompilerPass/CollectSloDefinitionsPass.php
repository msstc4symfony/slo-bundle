<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass;

use LogicException;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Discovery\DiscoveryErrors;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery\AttributeReader;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery\ControllerReader;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery\DefaultsReader;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery\HandlerReader;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery\MessengerRouting;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery\MetricsParameters;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery\OperationReader;
use Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery\TaggedPointReader;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CatalogDump;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsBundleSeriesCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexFile;
use Override;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Builds the SLO catalog from controllers, Messenger handlers, tagged points and configured operations (spec 5.2).
 * Runs TYPE_BEFORE_OPTIMIZATION, priority 0: after attribute autoconfiguration has added the tags.
 *
 * @internal
 */
final class CollectSloDefinitionsPass implements CompilerPassInterface
{
    public const DEFAULT_WINDOW = '30d';

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
        [$taggedPoints, $taggedWarnings] = (new TaggedPointReader($attributes, $errors))->read($container);
        $operations = (new OperationReader($assembler))->read($this->parameter($container, 'operations'));

        $extras = new CatalogExtras(
            $controllers,
            $handlers,
            $taggedPoints,
            $operations,
            MessengerRouting::failureTransports($container, $this->parameter($container, 'defaults.messenger.failure_transports')),
            MetricsParameters::buckets($container),
            MetricsParameters::httpIgnoredRoutes($container),
            [...$warnings, ...$taggedWarnings],
            $controllerReader->serviceIds($container),
        );
        $contents = $assembler->assemble(DefaultsReader::factory($container), $errors, $extras);

        if (!$errors->isEmpty()) {
            throw new InvalidConfigurationException($errors->summary());
        }

        $dump = self::escape(CatalogDump::toArray($contents, new MetricsBundleSeriesCatalog()));
        $container->getDefinition(CompiledSloCatalog::class)->setArgument('$catalog', $dump);
        if ($container->hasDefinition(RouteIndexFile::class)) {
            $container->getDefinition(RouteIndexFile::class)->setArgument('$fingerprint', $this->fingerprint($dump));
        }
    }

    /**
     * String values of a service argument are parameter-resolved ("%name%") and the dump holds user strings
     * (IgnoreMonitoring reasons, tag attributes): every "%" is doubled, the container turns "%%" back into "%".
     * Keys are not unescaped by ContainerBuilder, so a "%" in a key is rejected here for every reader at once.
     *
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     *
     * @throws LogicException when a key contains "%"
     */
    public static function escape(array $value): array
    {
        $escaped = [];
        foreach ($value as $key => $item) {
            if (\is_string($key) && str_contains($key, '%')) {
                throw new LogicException(\sprintf('The compiled SLO catalog cannot hold the key "%s": "%%" is not allowed in dumped keys.', $key));
            }
            $escaped[$key] = match (true) {
                \is_string($item) => str_replace('%', '%%', $item),
                \is_array($item) => self::escape($item),
                default => $item,
            };
        }

        return $escaped;
    }

    /**
     * @param array<array-key, mixed> $dump
     *
     * @return non-empty-string
     */
    private function fingerprint(array $dump): string
    {
        return hash('xxh128', serialize($dump));
    }

    private function parameter(ContainerBuilder $container, string $path): mixed
    {
        $name = self::PARAMETER_PREFIX . $path;

        return $container->hasParameter($name) ? $container->getParameterBag()->resolveValue($container->getParameter($name)) : null;
    }
}
