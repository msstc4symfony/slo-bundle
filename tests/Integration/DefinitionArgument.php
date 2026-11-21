<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration;

use LogicException;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Definition;

/**
 * Compiled definitions hold positional arguments: looks the position up by parameter name.
 */
final class DefinitionArgument
{
    /**
     * @param class-string|ReflectionMethod $method a class (its constructor) or the factory method
     */
    public static function of(Definition $definition, string|ReflectionMethod $method, string $name): mixed
    {
        $method = \is_string($method) ? new ReflectionMethod($method, '__construct') : $method;
        foreach ($method->getParameters() as $parameter) {
            if ($parameter->getName() === $name) {
                return $definition->getArgument($parameter->getPosition());
            }
        }

        throw new LogicException(\sprintf('%s has no parameter $%s.', $method->getName(), $name));
    }
}
