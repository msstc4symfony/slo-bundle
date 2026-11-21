<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\DependencyInjection;

use Closure;
use Msstc4Symfony\LogicBundle\Application\Action\ActionOutcome;
use Msstc4Symfony\LogicBundle\Application\Action\ExpectedFailureInterface;
use Msstc4Symfony\SloBundle\Application\Port\ExpectedFailureClassifierInterface;
use Msstc4Symfony\SloBundle\Infrastructure\LogicBundle\ActionOutcomeClassifier;
use Msstc4Symfony\SloBundle\Infrastructure\LogicBundle\ExpectedFailureInterfaceClassifier;
use Msstc4Symfony\SloBundle\Infrastructure\LogicBundle\NullExpectedFailureClassifier;
use Symfony\Component\Config\Resource\ClassExistenceResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Picks the ExpectedFailureClassifierInterface adapter for the installed logic-bundle (optional) and makes the
 * container rebuild when logic-bundle is installed, upgraded past ActionOutcome or removed.
 *
 * @internal
 */
final class ExpectedFailureClassifierSelector
{
    /**
     * @param (Closure(class-string): bool)|null $exists probe for a class, interface or enum; defaults to autoloading it
     *
     * @return class-string<ExpectedFailureClassifierInterface>
     */
    public static function select(ContainerBuilder $container, ?Closure $exists = null): string
    {
        $exists ??= static fn (string $name): bool => class_exists($name) || interface_exists($name) || enum_exists($name);
        $container->addResource(new ClassExistenceResource(ActionOutcome::class, false));
        $container->addResource(new ClassExistenceResource(ExpectedFailureInterface::class, false));

        return match (true) {
            $exists(ActionOutcome::class) => ActionOutcomeClassifier::class,
            $exists(ExpectedFailureInterface::class) => ExpectedFailureInterfaceClassifier::class,
            default => NullExpectedFailureClassifier::class,
        };
    }
}
