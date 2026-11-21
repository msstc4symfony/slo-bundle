<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery;

use Error;
use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Attribute\IgnoreMonitoring;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Discovery\DiscoveryErrors;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use ReflectionClass;
use ReflectionMethod;

/**
 * @internal
 */
final readonly class AttributeReader
{
    public function __construct(
        private CatalogAssembler $assembler,
        private DiscoveryErrors $errors,
    ) {
    }

    /**
     * @param ReflectionClass<object>|ReflectionMethod $reflection
     *
     * @return bool whether at least one #[Slo] is declared
     */
    public function apply(PointId $point, ReflectionClass|ReflectionMethod $reflection): bool
    {
        $declared = false;
        try {
            foreach ($reflection->getAttributes(Slo::class) as $attribute) {
                $this->assembler->declare($point, $attribute->newInstance());
                $declared = true;
            }
            foreach ($reflection->getAttributes(IgnoreMonitoring::class) as $attribute) {
                $reason = trim($attribute->newInstance()->reason);
                if ($reason !== '') {
                    $this->assembler->ignore($point, $reason);
                }
            }
        } catch (InvalidArgumentException|Error $e) {
            $this->errors->add(\sprintf('%s: invalid attribute: %s', $point->toString(), $e->getMessage()));
        }

        return $declared;
    }
}
