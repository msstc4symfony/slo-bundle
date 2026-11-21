<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery;

use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Contracts\Service\Attribute\Required;

/**
 * Controllers are points "Class::method"; routes are bound later from the route collection (spec 5.2).
 *
 * @internal
 */
final readonly class ControllerReader
{
    public const TAG = 'controller.service_arguments';

    private const ABSTRACT_CONTROLLER = AbstractController::class;

    private const INVOKE = '__invoke';

    private const REQUIRED = Required::class;

    public function __construct(private AttributeReader $attributes)
    {
    }

    /**
     * @return array<class-string, list<non-empty-string>> every public action method per controller class
     */
    public function read(ContainerBuilder $container): array
    {
        $controllers = [];
        foreach (array_keys($container->findTaggedServiceIds(self::TAG)) as $id) {
            $class = ServiceClass::reflect($container, $id);
            if (!$class instanceof ReflectionClass || isset($controllers[$class->getName()])) {
                continue;
            }

            $methods = $this->actionMethods($class);
            $controllers[$class->getName()] = array_keys($methods);
            $invokable = isset($methods[self::INVOKE]);
            foreach ($methods as $name => $method) {
                $point = PointId::of(PointType::HttpRoute, $class->getName() . '::' . $name);
                $this->attributes->apply($point, $method);
                // Invokable: class and __invoke are one target, so both apply (and may conflict).
                // Non-invokable: method-level attributes override the class-level ones for that method.
                $inherits = $invokable ? $name === self::INVOKE : !AttributeReader::declaresAny($method);
                if ($inherits) {
                    $this->attributes->apply($point, $class);
                }
            }
        }
        ksort($controllers, SORT_STRING);

        return $controllers;
    }

    /**
     * Service ids of controllers that are not named after their class, for routes written as "service_id::method".
     *
     * @return array<non-empty-string, class-string>
     */
    public function serviceIds(ContainerBuilder $container): array
    {
        $services = [];
        foreach (array_keys($container->findTaggedServiceIds(self::TAG)) as $id) {
            $class = ServiceClass::reflect($container, $id);
            if ($class instanceof ReflectionClass && $id !== '' && $id !== $class->getName()) {
                $services[$id] = $class->getName();
            }
        }
        ksort($services, SORT_STRING);

        return $services;
    }

    /**
     * @param ReflectionClass<object> $class
     *
     * @return array<non-empty-string, ReflectionMethod> sorted by name
     */
    private function actionMethods(ReflectionClass $class): array
    {
        $methods = [];
        foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if ($name === ''
                || $method->isStatic()
                || $method->isAbstract()
                || $method->getAttributes(self::REQUIRED) !== []
                || (str_starts_with($name, '__') && $name !== self::INVOKE)
                || $method->getDeclaringClass()->getName() === self::ABSTRACT_CONTROLLER) {
                continue;
            }
            $methods[$name] = $method;
        }
        ksort($methods, SORT_STRING);

        return $methods;
    }
}
