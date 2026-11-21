<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration;

use Closure;
use Override;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Changes the container after the extensions are loaded, e.g. to break a bundle parameter before discovery.
 */
final readonly class CallbackPass implements CompilerPassInterface
{
    /**
     * @param Closure(ContainerBuilder): void $callback
     */
    public function __construct(private Closure $callback)
    {
    }

    #[Override]
    public function process(ContainerBuilder $container): void
    {
        ($this->callback)($container);
    }
}
