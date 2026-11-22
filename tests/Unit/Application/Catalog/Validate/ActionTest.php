<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\Validate;

use ArrayObject;
use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Catalog\Validate\Action;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Discovery\HandlerInfo;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsBundleSeriesCatalog;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\InMemoryRouteCatalog;
use PHPUnit\Framework\TestCase;
use stdClass;

final class ActionTest extends TestCase
{
    public function testConflictNamesBothPointsAndOnlyDifferingFields(): void
    {
        $catalog = CatalogFixture::build(function ($assembler): void {
            $assembler->declare($this->http('A::create'), new Slo('payment.create', [new Availability(99.9)]));
            $assembler->declare($this->http('A::createV2'), new Slo('payment.create', [new Availability(99.5)]));
        });

        $output = (new Action($catalog, $catalog, new InMemoryRouteCatalog(), new MetricBindingResolver(new InMemoryRouteCatalog(), $catalog, new MetricsBundleSeriesCatalog())))();

        self::assertFalse($output->isValid());
        self::assertCount(1, $output->errors);
        self::assertStringStartsWith('SLO payment.create is declared differently: http_route:A::create (target 99.9) vs http_route:A::createV2 (target 99.5)', $output->errors[0]);
        self::assertStringContainsString('target 99.9 vs 99.5', $output->errors[0]);
        self::assertStringNotContainsString('window', $output->errors[0]);
    }

    public function testSameNameTwiceOnOnePointIsReported(): void
    {
        $catalog = CatalogFixture::build(function ($assembler): void {
            $assembler->declare($this->http('A::create'), new Slo('payment.create', [new Availability(99.9)]));
            $assembler->declare($this->http('A::create'), new Slo('payment.create', [new Availability(99.9)]));
        });

        $output = (new Action($catalog, $catalog, new InMemoryRouteCatalog(), new MetricBindingResolver(new InMemoryRouteCatalog(), $catalog, new MetricsBundleSeriesCatalog())))();

        self::assertFalse($output->isValid());
        self::assertStringContainsString('identical, declared twice', $output->errors[0]);
    }

    public function testObjectiveKindMissingOnOneSideIsADifference(): void
    {
        $catalog = CatalogFixture::build(function ($assembler): void {
            $assembler->declare($this->http('A::a'), new Slo('x.y', [new Availability(99.9)]));
            $assembler->declare($this->http('A::b'), new Slo('x.y', [new Availability(99.9), new Latency('200ms', 99)], window: '7d'));
        });

        $output = (new Action($catalog, $catalog, new InMemoryRouteCatalog(), new MetricBindingResolver(new InMemoryRouteCatalog(), $catalog, new MetricsBundleSeriesCatalog())))();

        self::assertStringContainsString('window 30d vs 7d', $output->errors[0]);
        self::assertStringContainsString('latency objective absent vs declared', $output->errors[0]);
    }

    public function testWindowsOfEqualLengthAreNotADifference(): void
    {
        $catalog = CatalogFixture::build(function ($assembler): void {
            $assembler->declare($this->http('A::a'), new Slo('x.y', [new Availability(99.9)], window: '30d', badStatuses: ['5xx']));
            $assembler->declare($this->http('A::b'), new Slo('x.y', [new Availability(99.9)], window: '1mo', badStatuses: ['5xx', 429]));
        });

        $output = (new Action($catalog, $catalog, new InMemoryRouteCatalog(), new MetricBindingResolver(new InMemoryRouteCatalog(), $catalog, new MetricsBundleSeriesCatalog())))();

        self::assertStringContainsString('bad statuses 5xx vs 429,5xx', $output->errors[0]);
        self::assertStringNotContainsString('window', $output->errors[0]);
    }

    public function testSharedSloIsANoticeNotAnError(): void
    {
        $catalog = CatalogFixture::build(function ($assembler): void {
            $assembler->declare($this->http('A::create'), new Slo('payment.shared', [new Availability(99.9)]));
            $assembler->declare($this->http('A::createV2'), new Slo('payment.shared', [new Availability(99.9)]));
        });
        $routes = new InMemoryRouteCatalog([
            new RouteInfo('create', '/a', ['POST'], 'A::create'),
            new RouteInfo('create_v2', '/b', ['POST'], 'A::createV2'),
        ]);

        $output = (new Action($catalog, $catalog, $routes, new MetricBindingResolver($routes, $catalog, new MetricsBundleSeriesCatalog())))();

        self::assertTrue($output->isValid());
        self::assertSame(['SLO payment.shared is shared by 2 points: http_route:A::create, http_route:A::createV2'], $output->notices);
    }

    public function testHttpPointWithoutRouteIsAnError(): void
    {
        $catalog = CatalogFixture::build(function ($assembler): void {
            $assembler->declare($this->http('A::create'), new Slo('payment.create', [new Availability(99.9)]));
            $assembler->declare(PointId::of(PointType::MessengerMessage, 'App\Msg'), new Slo('msg.handle', [new Availability(99)]));
        });

        $output = (new Action($catalog, $catalog, new InMemoryRouteCatalog(), new MetricBindingResolver(new InMemoryRouteCatalog(), $catalog, new MetricsBundleSeriesCatalog())))();

        self::assertSame(['SLO payment.create: A::create has no route'], $output->errors);
    }

    public function testRouteIgnoredByMetricsBundleIsAnError(): void
    {
        $catalog = CatalogFixture::build(
            function ($assembler): void {
                $assembler->declare($this->http('A::create'), new Slo('payment.create', [new Availability(99.9)]));
            },
            new CatalogExtras(httpIgnoredRoutes: ['create'], warnings: ['b warning', 'a warning']),
        );
        $routes = new InMemoryRouteCatalog([new RouteInfo('create', '/a', ['POST'], 'A::create')]);

        $output = (new Action($catalog, $catalog, $routes, new MetricBindingResolver($routes, $catalog, new MetricsBundleSeriesCatalog())))();

        self::assertCount(1, $output->errors);
        self::assertStringContainsString('route create of A::create is in the ignored routes of metrics-bundle', $output->errors[0]);
        self::assertSame(['a warning', 'b warning'], $output->warnings);
    }

    public function testBurnRatePairsReachingTheWholeBudgetAreWarnings(): void
    {
        $catalog = CatalogFixture::build(function ($assembler): void {
            $assembler->declare(PointId::of(PointType::Operation, 'report.export'), new Slo('report.export', [new Availability(90)]));
        });

        $output = (new Action($catalog, $catalog, new InMemoryRouteCatalog(), new MetricBindingResolver(new InMemoryRouteCatalog(), $catalog, new MetricsBundleSeriesCatalog())))();

        self::assertTrue($output->isValid());
        self::assertSame(['SLO report.export availability: page burn-rate alert 1h/5m omitted, factor 14.4 x error budget 0.1 >= 1'], $output->warnings);
    }

    public function testSloOnASyncOnlyHandlerIsAWarning(): void
    {
        $sync = 'App\Message\Sync';
        $async = 'App\Message\Async';
        $catalog = CatalogFixture::build(
            static function ($assembler) use ($sync, $async): void {
                $assembler->declare(PointId::of(PointType::MessengerMessage, $sync), new Slo('sync.handle', [new Availability(99)]));
                $assembler->declare(PointId::of(PointType::MessengerMessage, $async), new Slo('async.handle', [new Availability(99)]));
            },
            new CatalogExtras(handlers: [
                new HandlerInfo(stdClass::class, $sync, false, null),
                new HandlerInfo(stdClass::class, $async, true, null),
                new HandlerInfo(ArrayObject::class, $async, false, null),
            ]),
        );

        $output = (new Action($catalog, $catalog, new InMemoryRouteCatalog(), new MetricBindingResolver(new InMemoryRouteCatalog(), $catalog, new MetricsBundleSeriesCatalog())))();

        self::assertTrue($output->isValid());
        self::assertSame(['SLO sync.handle: messenger_message:App\Message\Sync is not consumed by a worker (routed nowhere or only to sync://); sync handling is not measured, the SLO never records events'], $output->warnings);
    }

    public function testAlertsWithoutRecordingRulesAreAWarning(): void
    {
        $catalog = CatalogFixture::build(static function (): void {
        });
        $resolver = new MetricBindingResolver(new InMemoryRouteCatalog(), $catalog, new MetricsBundleSeriesCatalog());

        $output = (new Action($catalog, $catalog, new InMemoryRouteCatalog(), $resolver, prometheusRules: false))();

        self::assertTrue($output->isValid());
        self::assertSame(['generation.prometheus.alerts is enabled but generation.prometheus.rules is not: the alert rules read slo:* records that are not generated'], $output->warnings);
        self::assertSame([], (new Action($catalog, $catalog, new InMemoryRouteCatalog(), $resolver, prometheusRules: false, prometheusAlerts: false))()->warnings);
    }

    public function testCleanCatalogIsValid(): void
    {
        $catalog = CatalogFixture::build(function ($assembler): void {
            $assembler->declare($this->http('A::create'), new Slo('payment.create', [new Availability(99.9)]));
        });
        $routes = new InMemoryRouteCatalog([new RouteInfo('create', '/a', ['POST'], 'A::create')]);

        $output = (new Action($catalog, $catalog, $routes, new MetricBindingResolver($routes, $catalog, new MetricsBundleSeriesCatalog())))();

        self::assertTrue($output->isValid());
        self::assertSame([], $output->warnings);
        self::assertSame([], $output->notices);
    }

    public function testAutoLatencyThresholdOutsideTheBucketsIsANoticeAboutDedicatedCounters(): void
    {
        $catalog = CatalogFixture::build(
            function ($assembler): void {
                $assembler->declare($this->http('A::create'), new Slo('payment.create', [new Availability(99.9), new Latency('200ms', 99, true)]));
                $assembler->declare($this->http('A::fast'), new Slo('payment.fast', [new Latency('500ms', 99, true)]));
                $assembler->declare($this->http('A::forced'), new Slo('payment.forced', [new Latency('200ms', 99)], strategy: StrategyPreference::Dedicated));
            },
            new CatalogExtras(metricBuckets: ['request_duration_histogram_seconds' => [0.1, 0.5, 1.0]]),
        );
        $routes = new InMemoryRouteCatalog([
            new RouteInfo('create', '/a', ['POST'], 'A::create'),
            new RouteInfo('fast', '/b', ['POST'], 'A::fast'),
            new RouteInfo('forced', '/c', ['POST'], 'A::forced'),
        ]);

        $output = (new Action($catalog, $catalog, $routes, new MetricBindingResolver($routes, $catalog, new MetricsBundleSeriesCatalog())))();

        self::assertTrue($output->isValid());
        self::assertSame(['SLO payment.create: latency threshold 200ms is not a bucket of request_duration_histogram_seconds, uses dedicated counters'], $output->notices);
    }

    /**
     * @param non-empty-string $id
     */
    private function http(string $id): PointId
    {
        return PointId::of(PointType::HttpRoute, $id);
    }
}
