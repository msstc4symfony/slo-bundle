<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery;

use Msstc4Symfony\SloBundle\Application\Discovery\DiscoveryErrors;
use Msstc4Symfony\SloBundle\Application\Discovery\PointTag;
use Msstc4Symfony\SloBundle\Application\Discovery\TaggedPoint;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use ReflectionClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * @internal
 */
final readonly class TaggedPointReader
{
    public function __construct(
        private AttributeReader $attributes,
        private DiscoveryErrors $errors,
    ) {
    }

    /**
     * @return list<TaggedPoint> sorted by point
     */
    public function read(ContainerBuilder $container): array
    {
        $points = [];
        foreach ($container->findTaggedServiceIds(PointTag::TAG) as $id => $tags) {
            $class = ServiceClass::reflect($container, $id);
            if (!$class instanceof ReflectionClass) {
                continue;
            }
            foreach ($tags as $tag) {
                if (!\is_array($tag)) {
                    continue;
                }
                $point = $this->point($id, $tag);
                if (!$point instanceof PointId || isset($points[$point->toString()])) {
                    continue;
                }
                $label = $tag[PointTag::METRIC_LABEL] ?? null;
                $points[$point->toString()] = new TaggedPoint($point, \is_string($label) && $label !== '' ? $label : null);
                $this->attributes->apply($point, $class);
            }
        }
        ksort($points, SORT_STRING);

        return array_values($points);
    }

    /**
     * @param array<array-key, mixed> $tag
     */
    private function point(string $id, array $tag): ?PointId
    {
        $type = $tag[PointTag::TYPE] ?? null;
        $type = \is_string($type) ? PointType::tryFrom($type) : null;

        $pointId = $tag[PointTag::ID] ?? null;
        if ($type instanceof PointType && \is_string($pointId) && $pointId !== '') {
            return PointId::of($type, $pointId);
        }

        $this->errors->add(\sprintf(
            'service "%s": tag "%s" needs attribute "%s" (one of %s) and a non-empty "%s".',
            $id,
            PointTag::TAG,
            PointTag::TYPE,
            implode('|', array_map(static fn (PointType $t): string => $t->value, PointType::cases())),
            PointTag::ID,
        ));

        return null;
    }
}
