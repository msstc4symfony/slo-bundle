<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery;

use ReflectionClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 */
final class ServiceClass
{
    /**
     * Resolves parameters in the class name; null for abstract definitions and classes that cannot be loaded.
     *
     * @return ReflectionClass<object>|null
     */
    public static function reflect(ContainerBuilder $container, string $id): ?ReflectionClass
    {
        $definition = $container->getDefinition($id);
        if ($definition->isAbstract()) {
            return null;
        }
        $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);

        return \is_string($class) ? $container->getReflectionClass($class, false) : null;
    }
}
