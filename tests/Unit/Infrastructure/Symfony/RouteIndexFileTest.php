<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouteIndexData;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouteIndexFile;
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
        RouteIndexFile::write($this->dir, $this->data());
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

    public function testRewritingReplacesTheIndex(): void
    {
        RouteIndexFile::write($this->dir, $this->data());
        RouteIndexFile::write($this->dir, RouteIndexData::fromRoutes([], []));

        self::assertSame([], (new RouteIndexFile($this->dir, false))->load()?->all());
    }

    public function testMissingFileLoadsNothing(): void
    {
        self::assertNull((new RouteIndexFile($this->dir, false))->load());
    }

    public function testDebugModeIgnoresTheFileBecauseRoutesMayChangeWithoutACacheClear(): void
    {
        RouteIndexFile::write($this->dir, $this->data());

        self::assertNull((new RouteIndexFile($this->dir, true))->load());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function malformedProvider(): iterable
    {
        yield 'not an array' => ['<?php return 42;'];
        yield 'other version' => ["<?php return ['version' => 0, 'routes' => [], 'controllers' => [], 'points' => []];"];
        yield 'route without path' => ["<?php return ['version' => 1, 'routes' => ['a' => ['methods' => [], 'controller' => null]], 'controllers' => [], 'points' => []];"];
        yield 'bad method list' => ["<?php return ['version' => 1, 'routes' => ['a' => ['path' => '/a', 'methods' => [1], 'controller' => null]], 'controllers' => [], 'points' => []];"];
        yield 'bad controller' => ["<?php return ['version' => 1, 'routes' => ['a' => ['path' => '/a', 'methods' => [], 'controller' => '']], 'controllers' => [], 'points' => []];"];
        yield 'empty route name' => ["<?php return ['version' => 1, 'routes' => ['' => ['path' => '/a', 'methods' => [], 'controller' => null]], 'controllers' => [], 'points' => []];"];
        yield 'controller of an unknown route' => ["<?php return ['version' => 1, 'routes' => [], 'controllers' => ['A::b' => ['a']], 'points' => []];"];
        yield 'invalid point' => ["<?php return ['version' => 1, 'routes' => [], 'controllers' => [], 'points' => ['a' => ['nope']]];"];
        yield 'point list not a list' => ["<?php return ['version' => 1, 'routes' => [], 'controllers' => [], 'points' => ['a' => 'http_route:A::b']];"];
        yield 'routes not an array' => ["<?php return ['version' => 1, 'routes' => 'a', 'controllers' => [], 'points' => []];"];
        yield 'controllers not an array' => ["<?php return ['version' => 1, 'routes' => [], 'controllers' => 'a', 'points' => []];"];
        yield 'points not an array' => ["<?php return ['version' => 1, 'routes' => [], 'controllers' => [], 'points' => 'a'];"];
        yield 'route not an array' => ["<?php return ['version' => 1, 'routes' => ['a' => '/a'], 'controllers' => [], 'points' => []];"];
        yield 'methods not a list' => ["<?php return ['version' => 1, 'routes' => ['a' => ['path' => '/a', 'methods' => ['x' => 'GET'], 'controller' => null]], 'controllers' => [], 'points' => []];"];
        yield 'empty controller key' => ["<?php return ['version' => 1, 'routes' => ['a' => ['path' => '/a', 'methods' => [], 'controller' => null]], 'controllers' => ['' => ['a']], 'points' => []];"];
        yield 'empty route list' => ["<?php return ['version' => 1, 'routes' => [], 'controllers' => [], 'points' => ['a' => []]];"];
        yield 'empty route name in a list' => ["<?php return ['version' => 1, 'routes' => ['a' => ['path' => '/a', 'methods' => [], 'controller' => 'A::b']], 'controllers' => ['A::b' => ['a', '']], 'points' => []];"];
        yield 'throws' => ["<?php throw new \\RuntimeException('broken');"];
    }

    #[DataProvider('malformedProvider')]
    public function testMalformedFileLoadsNothing(string $contents): void
    {
        (new Filesystem())->dumpFile($this->dir . '/' . RouteIndexFile::PATH, $contents);

        self::assertNull((new RouteIndexFile($this->dir, false))->load());
    }

    public function testLoadIsMemoised(): void
    {
        RouteIndexFile::write($this->dir, $this->data());
        $file = new RouteIndexFile($this->dir, false);
        $first = $file->load();
        (new Filesystem())->remove($this->dir);

        self::assertSame($first, $file->load());
    }

    public function testUnwritableDirectoryThrows(): void
    {
        (new Filesystem())->dumpFile($this->dir, 'a file, not a directory');

        $this->expectException(RuntimeException::class);
        RouteIndexFile::write($this->dir, $this->data());
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
