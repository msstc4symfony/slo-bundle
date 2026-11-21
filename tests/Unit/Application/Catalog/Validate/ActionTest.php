<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\Validate;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Catalog\Validate\Action;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\InMemoryRouteCatalog;
use PHPUnit\Framework\TestCase;

final class ActionTest extends TestCase
{
    public function testConflictNamesBothPointsAndOnlyDifferingFields(): void
    {
        $catalog = CatalogFixture::build(function ($assembler): void {
            $assembler->declare($this->http('A::create'), new Slo('payment.create', [new Availability(99.9)]));
            $assembler->declare($this->http('A::createV2'), new Slo('payment.create', [new Availability(99.5)]));
        });

        $output = (new Action($catalog, $catalog, new InMemoryRouteCatalog(), new MetricBindingResolver(new InMemoryRouteCatalog(), $catalog)))();

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

        $output = (new Action($catalog, $catalog, new InMemoryRouteCatalog(), new MetricBindingResolver(new InMemoryRouteCatalog(), $catalog)))();

        self::assertFalse($output->isValid());
        self::assertStringContainsString('identical, declared twice', $output->errors[0]);
    }

    public function testObjectiveKindMissingOnOneSideIsADifference(): void
    {
        $catalog = CatalogFixture::build(function ($assembler): void {
            $assembler->declare($this->http('A::a'), new Slo('x.y', [new Availability(99.9)]));
            $assembler->declare($this->http('A::b'), new Slo('x.y', [new Availability(99.9), new Latency('200ms', 99)], window: '7d'));
        });

        $output = (new Action($catalog, $catalog, new InMemoryRouteCatalog(), new MetricBindingResolver(new InMemoryRouteCatalog(), $catalog)))();

        self::assertStringContainsString('window 30d vs 7d', $output->errors[0]);
        self::assertStringContainsString('latency objective absent vs declared', $output->errors[0]);
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

        $output = (new Action($catalog, $catalog, $routes, new MetricBindingResolver($routes, $catalog)))();

        self::assertTrue($output->isValid());
        self::assertSame(['SLO payment.shared is shared by 2 points: http_route:A::create, http_route:A::createV2'], $output->notices);
    }

    public function testHttpPointWithoutRouteIsAnError(): void
    {
        $catalog = CatalogFixture::build(function ($assembler): void {
            $assembler->declare($this->http('A::create'), new Slo('payment.create', [new Availability(99.9)]));
            $assembler->declare(PointId::of(PointType::MessengerMessage, 'App\Msg'), new Slo('msg.handle', [new Availability(99)]));
        });

        $output = (new Action($catalog, $catalog, new InMemoryRouteCatalog(), new MetricBindingResolver(new InMemoryRouteCatalog(), $catalog)))();

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

        $output = (new Action($catalog, $catalog, $routes, new MetricBindingResolver($routes, $catalog)))();

        self::assertCount(1, $output->errors);
        self::assertStringContainsString('route create of A::create is in the ignored routes of metrics-bundle', $output->errors[0]);
        self::assertSame(['a warning', 'b warning'], $output->warnings);
    }

    public function testCleanCatalogIsValid(): void
    {
        $catalog = CatalogFixture::build(function ($assembler): void {
            $assembler->declare($this->http('A::create'), new Slo('payment.create', [new Availability(99.9)]));
        });
        $routes = new InMemoryRouteCatalog([new RouteInfo('create', '/a', ['POST'], 'A::create')]);

        $output = (new Action($catalog, $catalog, $routes, new MetricBindingResolver($routes, $catalog)))();

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
                $assembler->declare($this->http('A::forced'), new Slo('payment.forced', [new Latency('200ms', 99)], strategy: 'dedicated'));
            },
            new CatalogExtras(metricBuckets: ['request_duration_histogram_seconds' => [0.1, 0.5, 1.0]]),
        );
        $routes = new InMemoryRouteCatalog([
            new RouteInfo('create', '/a', ['POST'], 'A::create'),
            new RouteInfo('fast', '/b', ['POST'], 'A::fast'),
            new RouteInfo('forced', '/c', ['POST'], 'A::forced'),
        ]);

        $output = (new Action($catalog, $catalog, $routes, new MetricBindingResolver($routes, $catalog)))();

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
