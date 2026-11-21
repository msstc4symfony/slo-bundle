<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Filesystem;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\Filesystem\RouteIndexFile;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouteIndexData;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Filesystem\Filesystem;

final class RouteIndexFileTest extends TestCase
{
    private string $dir;

    #[Override]
    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/msstc4symfony-slo-route-index-' . getmypid();
        (new Filesystem())->remove($this->dir);
    }

    #[Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dir);
    }

    public function testWrittenIndexIsLoadedBack(): void
    {
        (new RouteIndexFile($this->dir, false))->write($this->dir, $this->data());
        $loaded = (new RouteIndexFile($this->dir, false))->load();

        self::assertInstanceOf(RouteIndexData::class, $loaded);
        self::assertEquals($this->data(), $loaded);
        self::assertSame(['pay_create', 'pay_create_v2', 'pay_list'], array_map(static fn (RouteInfo $r): string => $r->name, $loaded->all()));
        self::assertEquals([new RouteInfo('pay_create', '/pay', ['POST'], 'App\Pay::create'), new RouteInfo('pay_create_v2', '/v2/pay', ['POST'], 'App\Pay::create')], $loaded->routesFor('App\Pay::create'));
        self::assertSame([], $loaded->routesFor('Unknown::x'));
        self::assertEquals([PointId::of(PointType::HttpRoute, 'App\Pay::create')], $loaded->pointsFor('pay_create'));
        self::assertSame([], $loaded->pointsFor('pay_list'));
        self::assertStringStartsWith("<?php\n\nreturn ", (string) file_get_contents($this->dir . '/' . RouteIndexFile::PATH));
    }

    public function testNumericRouteNamesSurviveBuildingWritingAndLoading(): void
    {
        $point = PointId::of(PointType::HttpRoute, 'App\Error::show');
        $routes = [new RouteInfo('404', '/404', ['GET'], 'App\Error::show'), new RouteInfo('500', '/500', [], 'App\Error::show'), new RouteInfo('home', '/', [], null)];
        $built = RouteIndexData::fromRoutes($routes, ['404' => [$point]]);

        self::assertSame(['404', '500', 'home'], array_map(static fn (RouteInfo $r): string => $r->name, $built->all()));
        self::assertSame(['404', '500'], array_map(static fn (RouteInfo $r): string => $r->name, $built->routesFor('App\Error::show')));

        (new RouteIndexFile($this->dir, false))->write($this->dir, $built);
        $file = new RouteIndexFile($this->dir, false);
        $loaded = $file->load();

        self::assertInstanceOf(RouteIndexData::class, $loaded);
        self::assertSame(['404', '500', 'home'], array_map(static fn (RouteInfo $r): string => $r->name, $loaded->all()));
        self::assertEquals([$point], $loaded->pointsFor('404'));
        self::assertEquals(['404' => [$point]], $file->points());
    }

    public function testRewritingReplacesTheIndex(): void
    {
        (new RouteIndexFile($this->dir, false))->write($this->dir, $this->data());
        (new RouteIndexFile($this->dir, false))->write($this->dir, RouteIndexData::fromRoutes([], []));

        self::assertSame([], (new RouteIndexFile($this->dir, false))->load()?->all());
    }

    public function testMissingFileLoadsNothing(): void
    {
        self::assertNull((new RouteIndexFile($this->dir, false))->load());
    }

    public function testDebugModeIgnoresTheFileBecauseRoutesMayChangeWithoutACacheClear(): void
    {
        (new RouteIndexFile($this->dir, false))->write($this->dir, $this->data());

        self::assertNull((new RouteIndexFile($this->dir, true))->load());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedProvider(): iterable
    {
        yield 'not an array' => ['<?php return 42;'];
        yield 'other version' => ["<?php return ['version' => 0, 'routes' => [], 'controllers' => [], 'points' => []];"];
        yield 'route without path' => ["<?php return ['version' => 2, 'revisions' => [], 'routes' => ['a' => ['methods' => [], 'controller' => null]], 'controllers' => [], 'points' => []];"];
        yield 'bad method list' => ["<?php return ['version' => 2, 'revisions' => [], 'routes' => ['a' => ['path' => '/a', 'methods' => [1], 'controller' => null]], 'controllers' => [], 'points' => []];"];
        yield 'bad controller' => ["<?php return ['version' => 2, 'revisions' => [], 'routes' => ['a' => ['path' => '/a', 'methods' => [], 'controller' => '']], 'controllers' => [], 'points' => []];"];
        yield 'empty route name' => ["<?php return ['version' => 2, 'revisions' => [], 'routes' => ['' => ['path' => '/a', 'methods' => [], 'controller' => null]], 'controllers' => [], 'points' => []];"];
        yield 'controller of an unknown route' => ["<?php return ['version' => 2, 'revisions' => [], 'routes' => [], 'controllers' => ['A::b' => ['a']], 'points' => []];"];
        yield 'invalid point' => ["<?php return ['version' => 2, 'revisions' => [], 'routes' => [], 'controllers' => [], 'points' => ['a' => ['nope']]];"];
        yield 'point list not a list' => ["<?php return ['version' => 2, 'revisions' => [], 'routes' => [], 'controllers' => [], 'points' => ['a' => 'http_route:A::b']];"];
        yield 'routes not an array' => ["<?php return ['version' => 2, 'revisions' => [], 'routes' => 'a', 'controllers' => [], 'points' => []];"];
        yield 'controllers not an array' => ["<?php return ['version' => 2, 'revisions' => [], 'routes' => [], 'controllers' => 'a', 'points' => []];"];
        yield 'points not an array' => ["<?php return ['version' => 2, 'revisions' => [], 'routes' => [], 'controllers' => [], 'points' => 'a'];"];
        yield 'route not an array' => ["<?php return ['version' => 2, 'revisions' => [], 'routes' => ['a' => '/a'], 'controllers' => [], 'points' => []];"];
        yield 'methods not a list' => ["<?php return ['version' => 2, 'revisions' => [], 'routes' => ['a' => ['path' => '/a', 'methods' => ['x' => 'GET'], 'controller' => null]], 'controllers' => [], 'points' => []];"];
        yield 'empty controller key' => ["<?php return ['version' => 2, 'revisions' => [], 'routes' => ['a' => ['path' => '/a', 'methods' => [], 'controller' => null]], 'controllers' => ['' => ['a']], 'points' => []];"];
        yield 'empty route list' => ["<?php return ['version' => 2, 'revisions' => [], 'routes' => [], 'controllers' => [], 'points' => ['a' => []]];"];
        yield 'empty route name in a list' => ["<?php return ['version' => 2, 'revisions' => [], 'routes' => ['a' => ['path' => '/a', 'methods' => [], 'controller' => 'A::b']], 'controllers' => ['A::b' => ['a', '']], 'points' => []];"];
        yield 'version 1 index' => ["<?php return ['version' => 1, 'routes' => [], 'controllers' => [], 'points' => []];"];
        yield 'revisions missing' => ["<?php return ['version' => 2, 'routes' => [], 'controllers' => [], 'points' => []];"];
        yield 'revisions not an array' => ["<?php return ['version' => 2, 'revisions' => 'a', 'routes' => [], 'controllers' => [], 'points' => []];"];
        yield 'bad revision key' => ["<?php return ['version' => 2, 'revisions' => ['Pay|availability' => ''], 'routes' => [], 'controllers' => [], 'points' => []];"];
        yield 'bad revision objective' => ["<?php return ['version' => 2, 'revisions' => ['pay|speed' => ''], 'routes' => [], 'controllers' => [], 'points' => []];"];
        yield 'bad revision value' => ["<?php return ['version' => 2, 'revisions' => ['pay|availability' => 'ABCDEF12'], 'routes' => [], 'controllers' => [], 'points' => []];"];
        yield 'numeric controller key' => ["<?php return ['version' => 2, 'revisions' => [], 'routes' => ['a' => ['path' => '/a', 'methods' => [], 'controller' => null]], 'controllers' => [7 => ['a']], 'points' => []];"];
        yield 'throws' => ["<?php throw new \\RuntimeException('broken');"];
    }

    #[DataProvider('malformedProvider')]
    public function testMalformedFileLoadsNothing(string $contents): void
    {
        (new Filesystem())->dumpFile($this->dir . '/' . RouteIndexFile::PATH, $contents);

        self::assertNull((new RouteIndexFile($this->dir, false))->load());
    }

    public function testHotPathSectionsAreValidatedIndependentlyOfTheRoutes(): void
    {
        (new Filesystem())->dumpFile($this->dir . '/' . RouteIndexFile::PATH, "<?php return ['version' => 2, 'routes' => 'not validated on the hot path', 'controllers' => [], 'points' => ['pay' => ['http_route:A::b']], 'revisions' => ['pay.create|availability' => 'abcdef12', 'pay.create|latency' => '']];");
        $file = new RouteIndexFile($this->dir, false);

        self::assertEquals(['pay' => [PointId::of(PointType::HttpRoute, 'A::b')]], $file->points());
        self::assertSame(['pay.create|availability' => 'abcdef12', 'pay.create|latency' => ''], $file->revisions());
        self::assertNull($file->load());
    }

    public function testLoadIsMemoised(): void
    {
        (new RouteIndexFile($this->dir, false))->write($this->dir, $this->data());
        $file = new RouteIndexFile($this->dir, false);
        $first = $file->load();
        (new Filesystem())->remove($this->dir);

        self::assertSame($first, $file->load());
    }

    public function testUnwritableDirectoryThrows(): void
    {
        (new Filesystem())->dumpFile($this->dir, 'a file, not a directory');

        $this->expectException(RuntimeException::class);
        (new RouteIndexFile($this->dir, false))->write($this->dir, $this->data());
    }

    public function testPointsAreComputedFromTheCatalog(): void
    {
        $catalog = CatalogFixture::build(static function (CatalogAssembler $assembler): void {
            $assembler->declare(PointId::of(PointType::HttpRoute, 'App\Pay::create'), new Slo('pay.create', [new Availability(99.9)]));
            $assembler->declare(PointId::of(PointType::HttpRoute, 'A::one'), new Slo('dup.slo', [new Availability(99.9)]));
            $assembler->declare(PointId::of(PointType::HttpRoute, 'A::two'), new Slo('dup.slo', [new Availability(99.5)]));
        });
        $routes = [...$this->routes(), new RouteInfo('one', '/one', [], 'A::one'), new RouteInfo('none', '/none', [], null)];

        self::assertEquals(
            ['one' => [PointId::of(PointType::HttpRoute, 'A::one')], 'pay_create' => [PointId::of(PointType::HttpRoute, 'App\Pay::create')], 'pay_create_v2' => [PointId::of(PointType::HttpRoute, 'App\Pay::create')]],
            RouteIndexData::pointsOf($routes, $catalog),
        );
    }

    private function data(): RouteIndexData
    {
        return RouteIndexData::fromRoutes($this->routes(), [
            'pay_create' => [PointId::of(PointType::HttpRoute, 'App\Pay::create')],
            'pay_create_v2' => [PointId::of(PointType::HttpRoute, 'App\Pay::create')],
        ]);
    }

    /**
     * @return list<RouteInfo>
     */
    private function routes(): array
    {
        return [
            new RouteInfo('pay_list', '/pay', ['GET'], 'App\Pay::list'),
            new RouteInfo('pay_create_v2', '/v2/pay', ['POST'], 'App\Pay::create'),
            new RouteInfo('pay_create', '/pay', ['POST'], 'App\Pay::create'),
        ];
    }
}
