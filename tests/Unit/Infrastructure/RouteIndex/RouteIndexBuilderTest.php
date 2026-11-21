<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\RouteIndex;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\FailingCatalog;
use PHPUnit\Framework\TestCase;

final class RouteIndexBuilderTest extends TestCase
{
    public function testPointsTheCallerAlreadyHasAreNotReadFromTheCatalogAgain(): void
    {
        $point = PointId::of(PointType::HttpRoute, 'App\Pay::create');
        $catalog = CatalogFixture::build(static function (CatalogAssembler $assembler) use ($point): void {
            $assembler->declare($point, new Slo('pay.create', [new Availability(99.9)]));
        });
        $noPointReads = new FailingCatalog($catalog, ['forPoint', 'conflictedNamesFor']);
        $routes = [new RouteInfo('pay_create', '/pay', ['POST'], 'App\Pay::create')];

        $data = RouteIndexFixture::builder($noPointReads, $catalog)->build($routes, ['pay_create' => [$point]]);

        self::assertEquals([$point], $data->pointsFor('pay_create'));
        self::assertSame(['pay.create|availability' => ''], $data->revisions());
    }

    public function testWithoutPointsTheyAreReadFromTheCatalog(): void
    {
        $point = PointId::of(PointType::HttpRoute, 'App\Pay::create');
        $catalog = CatalogFixture::build(static function (CatalogAssembler $assembler) use ($point): void {
            $assembler->declare($point, new Slo('pay.create', [new Availability(99.9)]));
        });

        $data = RouteIndexFixture::builder($catalog, $catalog)->build([new RouteInfo('pay_create', '/pay', ['POST'], 'App\Pay::create')]);

        self::assertEquals([$point], $data->pointsFor('pay_create'));
    }
}
