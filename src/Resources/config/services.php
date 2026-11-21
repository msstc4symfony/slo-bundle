<?php

declare(strict_types=1);

use Msstc4Symfony\SloBundle\Application\Greeting\Greet\Action;
use Msstc4Symfony\SloBundle\SloBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();
    $services->defaults()->autowire()->autoconfigure();

    $services->set(Action::class)
        ->arg('$greeting', param(SloBundle::GREETING_PARAMETER))
    ;
};
