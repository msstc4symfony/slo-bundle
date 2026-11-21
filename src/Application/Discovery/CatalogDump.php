<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Discovery;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Classification;
use Msstc4Symfony\SloBundle\Domain\ValueObject\LatencyThreshold;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Domain\ValueObject\StatusSet;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Target;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Window;

/**
 * Plain-array form of the compiled catalog: the container dumps it as a service argument, so it holds scalars only.
 *
 * @phpstan-type ObjectiveShape array{kind: value-of<ObjectiveKind>, target: float, threshold_ms: positive-int|null, include_errors: bool}
 * @phpstan-type DefinitionShape array{
 *     name: non-empty-string,
 *     point_type: value-of<PointType>,
 *     points: non-empty-list<non-empty-string>,
 *     objectives: non-empty-list<ObjectiveShape>,
 *     window: non-empty-string,
 *     bad_statuses: list<string>,
 *     ineligible_statuses: list<string>,
 *     expected_failure_is_good: bool,
 *     count_attempts: bool,
 *     strategy: value-of<StrategyPreference>,
 * }
 * @phpstan-type HandlerShape array{handler_class: class-string, message_class: non-empty-string, async: bool, from_transport: non-empty-string|null}
 * @phpstan-type TaggedPointShape array{point: non-empty-string, metric_label: non-empty-string|null}
 * @phpstan-type ExtrasShape array{
 *     controllers: array<class-string, list<non-empty-string>>,
 *     handlers: list<HandlerShape>,
 *     tagged_points: list<TaggedPointShape>,
 *     operations: list<non-empty-string>,
 *     failure_transports: list<non-empty-string>,
 *     metric_buckets: array<non-empty-string, list<float>>,
 *     http_ignored_routes: list<string>,
 *     warnings: list<non-empty-string>,
 * }
 * @phpstan-type CatalogShape array{
 *     definitions: list<DefinitionShape>,
 *     conflicts: array<non-empty-string, non-empty-list<DefinitionShape>>,
 *     ignored: array<non-empty-string, non-empty-string>,
 *     extras: ExtrasShape,
 * }
 */
final class CatalogDump
{
    /**
     * @return CatalogShape
     */
    public static function toArray(CatalogContents $contents): array
    {
        $extras = $contents->extras;

        return [
            'definitions' => array_map(self::definitionToArray(...), $contents->definitions),
            'conflicts' => array_map(
                static fn (array $declarations): array => array_map(static fn (SloDeclaration $d): array => self::definitionToArray($d->definition), $declarations),
                $contents->conflicts,
            ),
            'ignored' => $contents->ignored,
            'extras' => [
                'controllers' => $extras->controllers,
                'handlers' => array_map(static fn (HandlerInfo $h): array => [
                    'handler_class' => $h->handlerClass,
                    'message_class' => $h->messageClass,
                    'async' => $h->async,
                    'from_transport' => $h->fromTransport,
                ], $extras->handlers),
                'tagged_points' => array_map(static fn (TaggedPoint $t): array => [
                    'point' => $t->point->toString(),
                    'metric_label' => $t->metricLabel,
                ], $extras->taggedPoints),
                'operations' => $extras->operations,
                'failure_transports' => $extras->failureTransports,
                'metric_buckets' => $extras->metricBuckets,
                'http_ignored_routes' => $extras->httpIgnoredRoutes,
                'warnings' => $extras->warnings,
            ],
        ];
    }

    /**
     * @param CatalogShape $catalog
     */
    public static function fromArray(array $catalog): CatalogContents
    {
        $extras = $catalog['extras'];

        return new CatalogContents(
            array_map(self::definitionFromArray(...), $catalog['definitions']),
            array_map(
                static fn (array $declarations): array => array_map(static function (array $shape): SloDeclaration {
                    $definition = self::definitionFromArray($shape);

                    return new SloDeclaration($definition->points()[0], $definition);
                }, $declarations),
                $catalog['conflicts'],
            ),
            $catalog['ignored'],
            new CatalogExtras(
                $extras['controllers'],
                array_map(static fn (array $h): HandlerInfo => new HandlerInfo($h['handler_class'], $h['message_class'], $h['async'], $h['from_transport']), $extras['handlers']),
                array_map(static fn (array $t): TaggedPoint => new TaggedPoint(self::point($t['point']), $t['metric_label']), $extras['tagged_points']),
                $extras['operations'],
                $extras['failure_transports'],
                $extras['metric_buckets'],
                $extras['http_ignored_routes'],
                $extras['warnings'],
            ),
        );
    }

    /**
     * @param non-empty-string $point as produced by PointId::toString()
     */
    public static function point(string $point): PointId
    {
        [$type, $id] = explode(':', $point, 2) + [1 => ''];
        if ($id === '') {
            throw new InvalidArgumentException(\sprintf('Invalid point "%s": expected <type>:<id>.', $point));
        }

        return PointId::of(PointType::from($type), $id);
    }

    /**
     * @return DefinitionShape
     */
    private static function definitionToArray(SloDefinition $definition): array
    {
        $classification = $definition->classification();

        return [
            'name' => $definition->name()->value,
            'point_type' => $definition->pointType()->value,
            'points' => array_map(static fn (PointId $p): string => $p->toString(), $definition->points()),
            'objectives' => array_map(static fn (Objective $o): array => [
                'kind' => $o->kind->value,
                'target' => $o->target->percent,
                'threshold_ms' => $o->threshold?->milliseconds,
                'include_errors' => $o->includeErrors,
            ], $definition->objectives()),
            'window' => $definition->window()->toString(),
            'bad_statuses' => $classification->badStatuses->canonical(),
            'ineligible_statuses' => $classification->ineligibleStatuses->canonical(),
            'expected_failure_is_good' => $classification->expectedFailureIsGood,
            'count_attempts' => $classification->countAttempts,
            'strategy' => $definition->strategy()->value,
        ];
    }

    /**
     * @param DefinitionShape $shape
     */
    private static function definitionFromArray(array $shape): SloDefinition
    {
        return new SloDefinition(
            SloName::fromString($shape['name']),
            PointType::from($shape['point_type']),
            array_map(self::point(...), $shape['points']),
            array_map(self::objectiveFromArray(...), $shape['objectives']),
            Window::fromString($shape['window']),
            new Classification(
                StatusSet::fromList($shape['bad_statuses']),
                StatusSet::fromList($shape['ineligible_statuses']),
                $shape['expected_failure_is_good'],
                $shape['count_attempts'],
            ),
            StrategyPreference::from($shape['strategy']),
        );
    }

    /**
     * @param ObjectiveShape $shape
     */
    private static function objectiveFromArray(array $shape): Objective
    {
        $target = Target::fromPercent($shape['target']);
        $threshold = $shape['threshold_ms'] === null ? null : LatencyThreshold::fromString($shape['threshold_ms'] . 'ms');

        return match (ObjectiveKind::from($shape['kind'])) {
            ObjectiveKind::Availability => Objective::availability($target),
            ObjectiveKind::Latency => Objective::latency($threshold ?? throw new InvalidArgumentException('Latency objective without threshold.'), $target, $shape['include_errors']),
            ObjectiveKind::Combined => Objective::combined($threshold ?? throw new InvalidArgumentException('Combined objective without threshold.'), $target),
        };
    }
}
