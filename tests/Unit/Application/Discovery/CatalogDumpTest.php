<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Discovery;

use Closure;
use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Combined;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogDump;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Discovery\DefinitionFactory;
use Msstc4Symfony\SloBundle\Application\Discovery\DiscoveryErrors;
use Msstc4Symfony\SloBundle\Application\Discovery\HandlerInfo;
use Msstc4Symfony\SloBundle\Application\Discovery\TaggedPoint;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class CatalogDumpTest extends TestCase
{
    public function testRoundTripKeepsDefinitionsConflictsIgnoredAndExtras(): void
    {
        $assembler = new CatalogAssembler();
        $create = PointId::of(PointType::HttpRoute, 'App\Controller::create');
        $update = PointId::of(PointType::HttpRoute, 'App\Controller::update');
        $assembler->declare($create, new Slo('payment.create', [new Availability(99.95), new Latency('200ms', 99, true), new Combined('1.5s', 95)], window: '1mo', badStatuses: [429, '5xx'], ineligibleStatuses: ['400-404']));
        $assembler->declare($update, new Slo('payment.create', [new Availability(99.95), new Latency('200ms', 99, true), new Combined('1.5s', 95)], window: '1mo', badStatuses: ['5xx', 429], ineligibleStatuses: ['400-404']));
        $assembler->declare($create, new Slo('payment.conflict', [new Availability(99.9)]));
        $assembler->declare($update, new Slo('payment.conflict', [new Availability(99.5)]));
        $assembler->declare(PointId::of(PointType::MessengerMessage, 'App\Message\Pay'), new Slo('payment.process', [new Availability(99)], countAttempts: true, strategy: StrategyPreference::Dedicated));
        $assembler->ignore(PointId::of(PointType::HttpRoute, 'App\Controller::health'), 'probe');

        $extras = new CatalogExtras(
            [stdClass::class => ['create', 'update']],
            [new HandlerInfo(stdClass::class, 'App\Message\Pay', true, 'async')],
            [new TaggedPoint(PointId::of(PointType::LogicAction, 'refund'), 'RefundAction'), new TaggedPoint(PointId::of(PointType::LogicAction, 'cleanup'), null)],
            ['payment.validation'],
            ['failed'],
            ['request_duration_histogram_seconds' => [0.1, 1.0]],
            ['health'],
            ['a warning'],
        );
        $errors = new DiscoveryErrors();
        $contents = $assembler->assemble(new DefinitionFactory('30d', ['5xx'], [], true, false), $errors, $extras);
        self::assertTrue($errors->isEmpty(), $errors->summary());

        $dump = CatalogDump::toArray($contents);
        $restored = CatalogDump::fromArray($dump);

        self::assertEquals($contents, $restored);
        self::assertSame($dump, CatalogDump::toArray($restored));
        self::assertSame(['payment.create', 'payment.process'], array_column($dump['definitions'], 'name'));
        self::assertSame(['App\Controller::create', 'App\Controller::update'], array_map(static fn (PointId $p): string => $p->id, $restored->definitions[0]->points()));
        self::assertSame(['payment.conflict'], array_keys($restored->conflicts));
        self::assertSame($update->toString(), $restored->conflicts['payment.conflict'][1]->point->toString());
        self::assertSame(['http_route:App\Controller::health' => 'probe'], $restored->ignored);
        self::assertTrue($restored->extras->hasHttpPoints);
    }

    /**
     * @return iterable<string, array{Closure(CatalogAssembler): void, bool}>
     */
    public static function httpPointsProvider(): iterable
    {
        yield 'none' => [static function (): void {
        }, false];
        yield 'operation and message only' => [static function (CatalogAssembler $assembler): void {
            $assembler->declare(PointId::of(PointType::Operation, 'export'), new Slo('export', [new Availability(99)]));
            $assembler->declare(PointId::of(PointType::MessengerMessage, 'App\Pay'), new Slo('pay', [new Availability(99)]));
        }, false];
        yield 'ignored route only' => [static function (CatalogAssembler $assembler): void {
            $assembler->ignore(PointId::of(PointType::HttpRoute, 'A::health'), 'probe');
        }, false];
        yield 'http definition' => [static function (CatalogAssembler $assembler): void {
            $assembler->declare(PointId::of(PointType::HttpRoute, 'A::b'), new Slo('a.b', [new Availability(99)]));
        }, true];
        yield 'http conflict only' => [static function (CatalogAssembler $assembler): void {
            $assembler->declare(PointId::of(PointType::HttpRoute, 'A::b'), new Slo('dup', [new Availability(99)]));
            $assembler->declare(PointId::of(PointType::HttpRoute, 'A::c'), new Slo('dup', [new Availability(98)]));
        }, true];
    }

    /**
     * @param Closure(CatalogAssembler): void $declare
     */
    #[DataProvider('httpPointsProvider')]
    public function testAssemblerFlagsCatalogsWithHttpPoints(Closure $declare, bool $expected): void
    {
        $assembler = new CatalogAssembler();
        $declare($assembler);
        $contents = $assembler->assemble(new DefinitionFactory('30d', ['5xx'], [], true, false), new DiscoveryErrors(), new CatalogExtras(hasHttpPoints: !$expected));

        self::assertSame($expected, $contents->extras->hasHttpPoints);
        self::assertSame($expected, CatalogDump::toArray($contents)['extras']['has_http_points']);
    }

    public function testPointIdMayContainColons(): void
    {
        self::assertSame('App\X::run', CatalogDump::point('http_route:App\X::run')->id);
    }

    public function testInvalidPointIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CatalogDump::point('http_route');
    }
}
