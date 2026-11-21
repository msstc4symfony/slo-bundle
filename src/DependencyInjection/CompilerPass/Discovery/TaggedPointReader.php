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
        $owners = [];
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
                if (!$point instanceof PointId) {
                    continue;
                }
                $key = $point->toString();
                if (isset($owners[$key])) {
                    if ($owners[$key] !== $id) {
                        $this->errors->add(\sprintf('%s: tagged on services "%s" and "%s"; a tagged point id must be unique.', $key, $owners[$key], $id));
                    }

                    continue;
                }
                $owners[$key] = $id;
                $points[$key] = new TaggedPoint($point, $this->metricLabel($id, $tag));
                $this->attributes->apply($point, $class);
            }
        }
        ksort($points, SORT_STRING);

        return array_values($points);
    }

    /**
     * The label value reaches PromQL strings and Sloth's Go templates.
     *
     * @param array<array-key, mixed> $tag
     *
     * @return non-empty-string|null
     */
    private function metricLabel(string $id, array $tag): ?string
    {
        $label = $tag[PointTag::METRIC_LABEL] ?? null;
        if ($label === null || $label === '') {
            return null;
        }
        if (\is_string($label) && preg_match('/^[^\x00-\x1F\x7F]*$/Du', $label) === 1 && !str_contains($label, '{{') && !str_contains($label, '}}')) {
            return $label;
        }
        $this->errors->add(\sprintf('service "%s": tag "%s" attribute "%s" must be valid UTF-8 without control characters, "{{" or "}}".', $id, PointTag::TAG, PointTag::METRIC_LABEL));

        return null;
    }

    /**
     * @param array<array-key, mixed> $tag
     */
    private function point(string $id, array $tag): ?PointId
    {
        $type = $tag[PointTag::TYPE] ?? null;
        $type = \is_string($type) ? PointType::tryFrom($type) : null;

        $pointId = $tag[PointTag::ID] ?? null;
        if ($type instanceof PointType && \is_string($pointId) && $pointId !== '' && !str_contains($pointId, '%')) {
            return PointId::of($type, $pointId);
        }

        $this->errors->add(\sprintf(
            'service "%s": tag "%s" needs attribute "%s" (one of %s) and a non-empty "%s" without "%%".',
            $id,
            PointTag::TAG,
            PointTag::TYPE,
            implode('|', array_map(static fn (PointType $t): string => $t->value, PointType::cases())),
            PointTag::ID,
        ));

        return null;
    }
}
