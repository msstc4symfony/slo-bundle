<?php

declare(strict_types=1);

use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\abstract_arg;

return static function (ContainerConfigurator $container): void {
    $services = $container->services()->defaults()->autowire()->autoconfigure();

    $services->set(CompiledSloCatalog::class)
        ->arg('$catalog', abstract_arg('catalog dump, set by CollectSloDefinitionsPass'))
    ;
    $services->alias(SloCatalogInterface::class, CompiledSloCatalog::class);
};
