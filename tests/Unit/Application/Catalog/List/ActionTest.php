<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\List;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Binding\RevisionResolver;
use Msstc4Symfony\SloBundle\Application\Catalog\List\Action;
use Msstc4Symfony\SloBundle\Application\Catalog\List\Row;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsBundleSeriesCatalog;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\InMemoryRouteCatalog;
use PHPUnit\Framework\TestCase;

final class ActionTest extends TestCase
{
    public function testRowResolvesAllRoutesOfAPointAndSortsObjectives(): void
    {
        $catalog = CatalogFixture::build(function ($assembler): void {
            $assembler->declare($this->http('A::create'), new Slo('payment.create', [new Latency('200ms', 99), new Availability(99.95)]));
        });
        $routes = new InMemoryRouteCatalog([
            new RouteInfo('payment_update', '/payments/{id}', ['PUT'], 'A::create'),
            new RouteInfo('payment_create', '/payments', ['POST'], 'A::create'),
            new RouteInfo('other', '/o', [], 'B::x'),
        ]);

        $rows = (new Action($catalog, $catalog, $routes, new RevisionResolver(new RevisionCalculator(), $catalog), new MetricBindingResolver($routes, $catalog, new MetricsBundleSeriesCatalog())))()->rows;

        self::assertCount(1, $rows);
        self::assertSame('ok', $rows[0]->status);
        self::assertSame('http_route', $rows[0]->pointType);
        self::assertSame('30d', $rows[0]->window);
        self::assertSame(['payment_create', 'payment_update'], $rows[0]->points[0]->routes);
        self::assertSame(['availability', 'latency'], array_map(static fn ($o): string => $o->kind, $rows[0]->objectives));
        self::assertSame('200ms', $rows[0]->objectives[1]->threshold);
        self::assertNull($rows[0]->objectives[0]->threshold);
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $rows[0]->objectives[0]->revision);
        self::assertSame([], $rows[0]->sharedPoints);
    }

    public function testTaskRowCarriesTheRunDeadline(): void
    {
        $catalog = CatalogFixture::build(function ($assembler): void {
            $assembler->declare(PointId::of(PointType::ScheduledTask, 'App\\Cleanup::__invoke'), new Slo('cleanup.run', [new Availability(99)], deadline: '120s'));
            $assembler->declare(PointId::of(PointType::ScheduledTask, 'App\\Report::__invoke'), new Slo('report.run', [new Latency('5s', 99)]));
            $assembler->declare($this->http('A::ok'), new Slo('a.ok', [new Availability(99)]));
        });

        $rows = (new Action($catalog, $catalog, new InMemoryRouteCatalog(), new RevisionResolver(new RevisionCalculator(), $catalog), new MetricBindingResolver(new InMemoryRouteCatalog(), $catalog, new MetricsBundleSeriesCatalog())))()->rows;

        self::assertSame(['a.ok' => null, 'cleanup.run' => '2m', 'report.run' => '5s'], array_column(array_map(static fn (Row $r): array => [$r->name, $r->deadline], $rows), 1, 0));
    }

    public function testConflictsProduceOneDuplicateRowPerDeclaration(): void
    {
        $catalog = CatalogFixture::build(function ($assembler): void {
            $assembler->declare($this->http('A::create'), new Slo('payment.create', [new Availability(99.9)]));
            $assembler->declare($this->http('A::createV2'), new Slo('payment.create', [new Availability(99.5)]));
            $assembler->declare($this->http('A::ok'), new Slo('a.ok', [new Availability(99)]));
        });

        $rows = (new Action($catalog, $catalog, new InMemoryRouteCatalog(), new RevisionResolver(new RevisionCalculator(), $catalog), new MetricBindingResolver(new InMemoryRouteCatalog(), $catalog, new MetricsBundleSeriesCatalog())))()->rows;

        self::assertSame(['a.ok', 'payment.create', 'payment.create'], array_map(static fn (Row $r): string => $r->name, $rows));
        self::assertSame(['ok', 'duplicate', 'duplicate'], array_map(static fn (Row $r): string => $r->status, $rows));
        self::assertSame([99.9, 99.5], [$rows[1]->objectives[0]->target, $rows[2]->objectives[0]->target]);
        self::assertSame([], $rows[0]->points[0]->routes);
    }

    public function testSharedSloListsAllPoints(): void
    {
        $catalog = CatalogFixture::build(function ($assembler): void {
            $assembler->declare($this->http('A::b'), new Slo('payment.shared', [new Availability(99.9)]));
            $assembler->declare($this->http('A::a'), new Slo('payment.shared', [new Availability(99.9)]));
        });

        $rows = (new Action($catalog, $catalog, new InMemoryRouteCatalog(), new RevisionResolver(new RevisionCalculator(), $catalog), new MetricBindingResolver(new InMemoryRouteCatalog(), $catalog, new MetricsBundleSeriesCatalog())))()->rows;

        self::assertCount(1, $rows);
        self::assertSame(['http_route:A::a', 'http_route:A::b'], $rows[0]->sharedPoints);
        self::assertSame(['http_route:A::a', 'http_route:A::b'], array_map(static fn ($p): string => $p->point, $rows[0]->points));
    }

    public function testNonHttpPointsHaveNoRoutes(): void
    {
        $catalog = CatalogFixture::build(function ($assembler): void {
            $assembler->declare(PointId::of(PointType::MessengerMessage, 'App\Msg'), new Slo('msg.handle', [new Availability(99)]));
        });

        $rows = (new Action($catalog, $catalog, new InMemoryRouteCatalog(), new RevisionResolver(new RevisionCalculator(), $catalog), new MetricBindingResolver(new InMemoryRouteCatalog(), $catalog, new MetricsBundleSeriesCatalog())))()->rows;

        self::assertSame([], $rows[0]->points[0]->routes);
    }

    public function testObjectiveRowsCarryTheResolvedStrategyAndDuplicatesCarryNone(): void
    {
        $catalog = CatalogFixture::build(function ($assembler): void {
            $assembler->declare($this->http('A::create'), new Slo('payment.create', [new Availability(99.9), new Latency('200ms', 99)]));
            $assembler->declare($this->http('A::x'), new Slo('dup.x', [new Availability(99.9)]));
            $assembler->declare($this->http('A::y'), new Slo('dup.x', [new Availability(99)]));
        });
        $routes = new InMemoryRouteCatalog([new RouteInfo('create', '/a', ['POST'], 'A::create')]);

        $rows = (new Action($catalog, $catalog, $routes, new RevisionResolver(new RevisionCalculator(), $catalog), new MetricBindingResolver($routes, $catalog, new MetricsBundleSeriesCatalog())))()->rows;

        self::assertSame('dup.x', $rows[0]->name);
        self::assertSame([null], array_map(static fn ($o): ?string => $o->strategy, $rows[0]->objectives));
        self::assertSame(['existing_series', 'dedicated'], array_map(static fn ($o): ?string => $o->strategy, $rows[2]->objectives));
    }

    /**
     * @param non-empty-string $id
     */
    private function http(string $id): PointId
    {
        return PointId::of(PointType::HttpRoute, $id);
    }
}
