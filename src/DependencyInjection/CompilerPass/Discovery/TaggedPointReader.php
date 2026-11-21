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
     * @return array{list<TaggedPoint>, list<non-empty-string>} points sorted by point, warnings
     */
    public function read(ContainerBuilder $container): array
    {
        $points = [];
        $owners = [];
        $declared = [];
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
                    $this->checkRepeatedTag($key, $owners[$key], $id, $points[$key]->metricLabel, $tag);

                    continue;
                }
                $owners[$key] = $id;
                $points[$key] = new TaggedPoint($point, $this->metricLabel($id, $tag));
                $declared[$key] = $this->attributes->apply($point, $class);
            }
        }
        ksort($points, SORT_STRING);

        return $this->withoutSharedMetricLabels($points, $declared);
    }

    /**
     * @param array<array-key, mixed> $tag
     */
    private function checkRepeatedTag(string $key, string $owner, string $id, ?string $label, array $tag): void
    {
        if ($owner !== $id) {
            $this->errors->add(\sprintf('%s: tagged on services "%s" and "%s"; a tagged point id must be unique.', $key, $owner, $id));

            return;
        }
        $other = $this->metricLabel($id, $tag);
        if ($other !== $label) {
            $this->errors->add(\sprintf('%s: service "%s" is tagged twice with different "%s" values ("%s", "%s").', $key, $id, PointTag::METRIC_LABEL, $label ?? '', $other ?? ''));
        }
    }

    /**
     * Points of one type sharing a metric_label cannot be told apart in the bridge's series: an SLO on one of them
     * would count the others' events, so the label is dropped and the SLO falls back to dedicated counters.
     *
     * @param array<string, TaggedPoint> $points
     * @param array<string, bool> $declared point => declares #[Slo]
     *
     * @return array{list<TaggedPoint>, list<non-empty-string>}
     */
    private function withoutSharedMetricLabels(array $points, array $declared): array
    {
        $byLabel = [];
        foreach ($points as $key => $tagged) {
            if ($tagged->metricLabel !== null) {
                $byLabel[$tagged->point->type->value][$tagged->metricLabel][] = $key;
            }
        }
        $warnings = [];
        foreach ($byLabel as $labels) {
            foreach ($labels as $label => $keys) {
                if (\count($keys) < 2) {
                    continue;
                }
                foreach ($keys as $key) {
                    if ($declared[$key] ?? false) {
                        $warnings[] = \sprintf('%s: metric_label "%s" is also used by %s; their series cannot be told apart, so the SLO uses dedicated counters.', $key, $label, implode(', ', array_diff($keys, [$key])));
                    }
                    $points[$key] = new TaggedPoint($points[$key]->point, null);
                }
            }
        }

        return [array_values($points), $warnings];
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
