<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\DependencyInjection\CompilerPass\Discovery;

use Msstc4Symfony\MetricsBundle\Application\Metric\BuiltInMetric;
use Msstc4Symfony\MetricsBundle\Application\Metric\MetricDefinitionInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Reads metrics-bundle parameters; a missing parameter yields empty values (RegisterSloMetricPass reports a missing
 * metrics-bundle).
 *
 * @internal
 */
final class MetricsParameters
{
    public const METRIC_ENUMS = 'msstc4symfony_metrics.metric_enums';

    public const HTTP_IGNORED_ROUTES = 'msstc4symfony_metrics.http.ignored_routes';

    public const DURATION_HISTOGRAMS = [
        'request_duration_histogram_seconds',
        'messenger_message_handling_duration_histogram_seconds',
        'logic_action_duration_histogram_seconds',
    ];

    /**
     * @return array<non-empty-string, list<float>> histogram name => buckets, sorted by name
     */
    public static function buckets(ContainerBuilder $container): array
    {
        if (!$container->hasParameter(self::METRIC_ENUMS)) {
            return [];
        }
        $declared = $container->getParameterBag()->resolveValue($container->getParameter(self::METRIC_ENUMS));
        // metrics-bundle lists BuiltInMetric itself; older layouts kept it implicit.
        $enums = array_unique([BuiltInMetric::class, ...array_values(array_filter(\is_array($declared) ? $declared : [], \is_string(...)))]);

        $buckets = [];
        foreach (self::DURATION_HISTOGRAMS as $name) {
            foreach ($enums as $enum) {
                if (!is_subclass_of($enum, MetricDefinitionInterface::class)) {
                    continue;
                }
                $case = $enum::tryFrom($name);
                if ($case instanceof MetricDefinitionInterface) {
                    $buckets[$name] = array_map(floatval(...), $case->getBatches());

                    break;
                }
            }
        }
        ksort($buckets, SORT_STRING);

        return $buckets;
    }

    /**
     * @return list<string>
     */
    public static function httpIgnoredRoutes(ContainerBuilder $container): array
    {
        $routes = $container->hasParameter(self::HTTP_IGNORED_ROUTES) ? $container->getParameter(self::HTTP_IGNORED_ROUTES) : [];

        return array_values(array_filter(\is_array($routes) ? $routes : [], \is_string(...)));
    }
}
