<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouteIndexData;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouteIndexFile;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouterRouteCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouterRouteReader;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\InvokableController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\PaymentController;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class RouterRouteCatalogTest extends TestCase
{
    public function testNormalisesEveryControllerForm(): void
    {
        $collection = new RouteCollection();
        $collection->add('b_class_method', new Route('/b', ['_controller' => PaymentController::class . '::create'], methods: ['POST']));
        $collection->add('a_array', new Route('/a', ['_controller' => [PaymentController::class, 'create']], methods: ['PUT']));
        $collection->add('c_invokable', new Route('/c', ['_controller' => InvokableController::class]));
        $collection->add('d_service', new Route('/d', ['_controller' => 'app.payment::create']));
        $collection->add('e_service_invokable', new Route('/e', ['_controller' => 'app.refund']));
        $collection->add('f_none', new Route('/f'));
        $collection->add('g_object', new Route('/g', ['_controller' => [new PaymentController(), 'health']]));

        $router = self::createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);
        $diagnostics = CatalogFixture::build(static function (): void {
        }, new CatalogExtras(controllerServices: ['app.payment' => PaymentController::class, 'app.refund' => InvokableController::class]));
        $catalog = new RouterRouteCatalog(new RouterRouteReader($router, $diagnostics), $this->noFile());

        self::assertSame(
            ['a_array', 'b_class_method', 'd_service'],
            array_map(static fn (RouteInfo $r): string => $r->name, $catalog->routesFor(PaymentController::class . '::create')),
        );
        self::assertSame(
            ['c_invokable', 'e_service_invokable'],
            array_map(static fn (RouteInfo $r): string => $r->name, $catalog->routesFor(InvokableController::class . '::__invoke')),
        );
        self::assertCount(1, $catalog->routesFor(PaymentController::class . '::health'));
        self::assertSame([], $catalog->routesFor('Unknown::method'));
        self::assertCount(7, $catalog->all());
        self::assertSame(['PUT'], $catalog->routesFor(PaymentController::class . '::create')[0]->methods);
        self::assertSame('/a', $catalog->routesFor(PaymentController::class . '::create')[0]->path);
        self::assertSame(['f_none'], array_map(static fn (RouteInfo $r): string => $r->name, array_values(array_filter($catalog->all(), static fn (RouteInfo $r): bool => $r->controller === null))));
    }

    public function testRouterIsReadOnceAndLazily(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::once())->method('getRouteCollection')->willReturn(new RouteCollection());
        $catalog = new RouterRouteCatalog(new RouterRouteReader($router, CatalogFixture::build(static function (): void {
        })), $this->noFile());

        $catalog->routesFor('A::b');
        $catalog->all();
        $catalog->routesFor('C::d');
    }

    public function testWithoutARouterThereAreNoRoutes(): void
    {
        $catalog = new RouterRouteCatalog(new RouterRouteReader(null, CatalogFixture::build(static function (): void {
        })), $this->noFile());

        self::assertSame([], $catalog->all());
        self::assertSame([], $catalog->routesFor('A::b'));
    }

    public function testWarmedRoutesAreUsedWithoutTouchingTheRouter(): void
    {
        $dir = sys_get_temp_dir() . '/msstc4symfony-slo-route-catalog-' . getmypid();
        RouteIndexFile::write($dir, RouteIndexData::fromRoutes([new RouteInfo('pay', '/pay', ['POST'], 'App\Pay::create')], []));
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::never())->method('getRouteCollection');

        try {
            $catalog = new RouterRouteCatalog(new RouterRouteReader($router, CatalogFixture::build(static function (): void {
            })), new RouteIndexFile($dir, false));

            self::assertEquals([new RouteInfo('pay', '/pay', ['POST'], 'App\Pay::create')], $catalog->routesFor('App\Pay::create'));
            self::assertCount(1, $catalog->all());
        } finally {
            (new Filesystem())->remove($dir);
        }
    }

    private function noFile(): RouteIndexFile
    {
        return new RouteIndexFile(sys_get_temp_dir() . '/msstc4symfony-slo-no-route-index', false);
    }
}
