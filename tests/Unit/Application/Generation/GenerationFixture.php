<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Generation;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Generation\BoundObjectiveCollector;
use Msstc4Symfony\SloBundle\Application\Generation\BurnRatePolicy;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateRules\Action;
use Msstc4Symfony\SloBundle\Application\Generation\GenerateRules\Input;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\PromQlBuilder;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\RuleGroupsBuilder;
use Msstc4Symfony\SloBundle\Infrastructure\Yaml\DeterministicYaml;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\InMemoryRouteCatalog;

/**
 * The catalog behind the golden files: HTTP availability (A) + latency 200ms (B), Messenger availability and
 * latency 1s (A), an operation (B) on a 3d window.
 */
final class GenerationFixture
{
    public const CHECKOUT = 'App\Controller\CheckoutController::pay';

    public static function catalog(float $checkoutLatencyTarget = 99.0, float $checkoutAvailabilityTarget = 99.9): CompiledSloCatalog
    {
        return CatalogFixture::build(
            static function (CatalogAssembler $assembler) use ($checkoutLatencyTarget, $checkoutAvailabilityTarget): void {
                $assembler->declare(PointId::of(PointType::HttpRoute, self::CHECKOUT), new Slo('checkout.pay', [new Availability($checkoutAvailabilityTarget), new Latency('200ms', $checkoutLatencyTarget)]));
                $assembler->declare(PointId::of(PointType::MessengerMessage, 'App\Message\ProcessPayment'), new Slo('payment.process', [new Latency('1s', 99), new Availability(99.5)]));
                $assembler->declare(PointId::of(PointType::Operation, 'report.export'), new Slo('report.export', [new Availability(99)], window: '3d', strategy: 'dedicated'));
            },
            new CatalogExtras(
                failureTransports: ['failed'],
                metricBuckets: [
                    'request_duration_histogram_seconds' => [0.05, 0.1, 0.25, 0.5, 1.0],
                    'messenger_message_handling_duration_histogram_seconds' => [0.1, 0.5, 1.0, 5.0],
                ],
            ),
        );
    }

    public static function routes(): InMemoryRouteCatalog
    {
        return new InMemoryRouteCatalog([new RouteInfo('checkout_pay', '/checkout/pay', ['POST'], self::CHECKOUT)]);
    }

    public static function collector(CompiledSloCatalog $catalog): BoundObjectiveCollector
    {
        return new BoundObjectiveCollector($catalog, new MetricBindingResolver(self::routes(), $catalog), new RevisionCalculator());
    }

    public static function action(CompiledSloCatalog $catalog): Action
    {
        return new Action(self::collector($catalog), new RuleGroupsBuilder(new PromQlBuilder(), new BurnRatePolicy(), new DeterministicYaml()), new BurnRatePolicy(), $catalog);
    }

    /**
     * @param positive-int|null $noEventsAlertHours
     */
    public static function input(?int $noEventsAlertHours = null, bool $rules = true, bool $alerts = true): Input
    {
        return new Input(new Selector('shop', ['worker', 'web']), $rules, $alerts, $noEventsAlertHours);
    }
}
