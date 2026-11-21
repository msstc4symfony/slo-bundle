<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouteIndexData;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RoutePointIndex;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouterRouteCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouterRouteReader;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\PaymentController;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\FailingCatalog;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\InMemoryRouteCatalog;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class RoutePointIndexTest extends TestCase
{
    public function testMapsRoutesToTheirSloPoints(): void
    {
        $index = new RoutePointIndex($this->routes(), $this->catalog(), $this->noFile(), new RecordingLogger());

        self::assertSame(['http_route:App\Pay::create'], $this->strings($index->pointsFor('pay_create')));
        self::assertSame(['http_route:App\Pay::create'], $this->strings($index->pointsFor('pay_create_v2')));
        self::assertSame([], $index->pointsFor('pay_list'));
        self::assertSame([], $index->pointsFor('no_controller'));
        self::assertSame([], $index->pointsFor('unknown'));
    }

    public function testConflictedPointsAreIndexedSoTheConflictIsReported(): void
    {
        $catalog = CatalogFixture::build(static function (CatalogAssembler $assembler): void {
            $assembler->declare(PointId::of(PointType::HttpRoute, 'A::one'), new Slo('dup.slo', [new Availability(99.9)]));
            $assembler->declare(PointId::of(PointType::HttpRoute, 'A::two'), new Slo('dup.slo', [new Availability(99.5)]));
        });
        $index = new RoutePointIndex(new InMemoryRouteCatalog([new RouteInfo('one', '/one', [], 'A::one')]), $catalog, $this->noFile(), new RecordingLogger());

        self::assertSame(['http_route:A::one'], $this->strings($index->pointsFor('one')));
    }

    public function testRouteCatalogIsReadOnceAndLazily(): void
    {
        $routes = $this->createMock(RouteCatalogInterface::class);
        $routes->expects(self::once())->method('all')->willReturn([new RouteInfo('pay_create', '/pay', [], 'App\Pay::create')]);
        $index = new RoutePointIndex($routes, $this->catalog(), $this->noFile(), new RecordingLogger());

        for ($i = 0; $i < 3; $i++) {
            self::assertCount(1, $index->pointsFor('pay_create'));
            self::assertSame([], $index->pointsFor('other'));
        }
    }

    public function testFailingRouteCatalogYieldsNoPointsAndIsLoggedOnce(): void
    {
        $routes = $this->createMock(RouteCatalogInterface::class);
        $routes->expects(self::once())->method('all')->willThrowException(new RuntimeException('router broken'));
        $logger = new RecordingLogger();
        $index = new RoutePointIndex($routes, $this->catalog(), $this->noFile(), $logger);

        for ($i = 0; $i < 2; $i++) {
            self::assertSame([], $index->pointsFor('pay_create'));
        }

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0][0]);
        self::assertInstanceOf(RuntimeException::class, $logger->contexts[0]['exception']);
    }

    public function testCorruptExtrasSectionDoesNotDisableHttpSlos(): void
    {
        $collection = new RouteCollection();
        $collection->add('pay_create', new Route('/pay', ['_controller' => 'app.pay::create']));
        $router = self::createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);
        $catalog = CatalogFixture::build(static function (CatalogAssembler $assembler): void {
            $assembler->declare(PointId::of(PointType::HttpRoute, PaymentController::class . '::create'), new Slo('pay.create', [new Availability(99.9)]));
        }, new CatalogExtras(controllerServices: ['app.pay' => PaymentController::class]));
        $failing = new FailingCatalog($catalog, ['extras', 'all']);
        $logger = new RecordingLogger();
        $index = new RoutePointIndex(new RouterRouteCatalog(new RouterRouteReader($router, $failing), $this->noFile()), $failing, $this->noFile(), $logger);

        self::assertSame(['http_route:' . PaymentController::class . '::create'], $this->strings($index->pointsFor('pay_create')));
        self::assertSame([], $logger->records);
    }

    public function testNumericRouteNameIsIndexed(): void
    {
        $index = new RoutePointIndex(new InMemoryRouteCatalog([new RouteInfo('404', '/pay', [], 'App\Pay::create')]), $this->catalog(), $this->noFile(), new RecordingLogger());

        self::assertSame(['http_route:App\Pay::create'], $this->strings($index->pointsFor('404')));
    }

    public function testResetRetriesAFailedBuildButKeepsASuccessfulOne(): void
    {
        $routes = $this->createMock(RouteCatalogInterface::class);
        $routes->expects(self::exactly(2))->method('all')->willReturnOnConsecutiveCalls(
            self::throwException(new RuntimeException('router broken')),
            [new RouteInfo('pay_create', '/pay', [], 'App\Pay::create')],
        );
        $index = new RoutePointIndex($routes, $this->catalog(), $this->noFile(), new RecordingLogger());

        self::assertSame([], $index->pointsFor('pay_create'));
        $index->reset();
        self::assertCount(1, $index->pointsFor('pay_create'));
        $index->reset();
        self::assertCount(1, $index->pointsFor('pay_create'));
    }

    public function testWarmedIndexIsUsedWithoutReadingRoutes(): void
    {
        $store = new InMemoryRouteIndexStore(RouteIndexData::fromRoutes([], ['pay_create' => [PointId::of(PointType::HttpRoute, 'App\Pay::create')]]));
        $routes = $this->createMock(RouteCatalogInterface::class);
        $routes->expects(self::never())->method('all');

        $index = new RoutePointIndex($routes, $this->catalog(), $store, new RecordingLogger());

        self::assertSame(['http_route:App\Pay::create'], $this->strings($index->pointsFor('pay_create')));
        self::assertSame([], $index->pointsFor('pay_list'));
    }

    private function noFile(): InMemoryRouteIndexStore
    {
        return new InMemoryRouteIndexStore();
    }

    private function routes(): InMemoryRouteCatalog
    {
        return new InMemoryRouteCatalog([
            new RouteInfo('no_controller', '/none', [], null),
            new RouteInfo('pay_create', '/pay', ['POST'], 'App\Pay::create'),
            new RouteInfo('pay_create_v2', '/v2/pay', ['POST'], 'App\Pay::create'),
            new RouteInfo('pay_list', '/pay', ['GET'], 'App\Pay::list'),
        ]);
    }

    private function catalog(): CompiledSloCatalog
    {
        return CatalogFixture::build(static function (CatalogAssembler $assembler): void {
            $assembler->declare(PointId::of(PointType::HttpRoute, 'App\Pay::create'), new Slo('pay.create', [new Availability(99.9)]));
        });
    }

    /**
     * @param list<PointId> $points
     *
     * @return list<string>
     */
    private function strings(array $points): array
    {
        return array_map(static fn (PointId $point): string => $point->toString(), $points);
    }
}
