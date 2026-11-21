<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle;

use Override;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

final class SloBundle extends AbstractBundle
{
    protected string $extensionAlias = 'msstc4symfony_slo';

    #[Override]
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()->end();
    }

    /**
     * Reason for the ignore: Symfony declares $config as a plain array; configure() guarantees the shape.
     * The identifier is listed twice because the method has two parent declarations
     * (AbstractBundle and ConfigurableExtensionInterface), each reporting it.
     *
     * @param array{} $config
     */
    #[Override] // @phpstan-ignore method.childParameterType, method.childParameterType
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import(__DIR__ . '/Resources/config/services.php');
    }
}
