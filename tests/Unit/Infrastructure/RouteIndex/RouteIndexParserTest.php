<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\RouteIndex;

use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexData;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RouteIndexParserTest extends TestCase
{
    public function testParsesItsOwnFormBack(): void
    {
        $data = $this->data();

        self::assertEquals($data, RouteIndexParser::parse($data->toArray()));
        self::assertNotNull(RouteIndexParser::parse(self::valid()), 'the base of the malformed cases is valid');
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideMalformed(): iterable
    {
        $valid = self::valid();

        yield 'not an array' => ['index'];
        yield 'other version' => [['version' => RouteIndexData::VERSION + 1] + $valid];
        yield 'routes missing' => [array_diff_key($valid, ['routes' => true])];
        yield 'route without path' => [['routes' => ['pay' => ['methods' => [], 'controller' => null]]] + $valid];
        yield 'route with empty name' => [['routes' => ['' => ['path' => '/', 'methods' => [], 'controller' => null]], 'controllers' => [], 'points' => []] + $valid];
        yield 'methods not a list' => [['routes' => ['pay' => ['path' => '/', 'methods' => ['a' => 'GET'], 'controller' => 'A::b']]] + $valid];
        yield 'empty controller' => [['routes' => ['pay' => ['path' => '/', 'methods' => [], 'controller' => '']]] + $valid];
        yield 'controller to unknown route' => [['controllers' => ['A::b' => ['nope']]] + $valid];
        yield 'controller with no routes' => [['controllers' => ['A::b' => []]] + $valid];
        yield 'point not a point id' => [['points' => ['pay' => ['nope']]] + $valid];
        yield 'point of unknown type' => [['points' => ['pay' => ['queue:x']]] + $valid];
        yield 'revision key without kind' => [['revisions' => ['pay.create' => 'abcdef12']] + $valid];
        yield 'revision in upper case' => [['revisions' => ['pay.create|latency' => 'ABCDEF12']] + $valid];
        yield 'revisions not an array' => [['revisions' => 'abcdef12'] + $valid];
    }

    #[DataProvider('provideMalformed')]
    public function testAMalformedIndexParsesAsNull(mixed $value): void
    {
        self::assertNull(RouteIndexParser::parse($value));
    }

    public function testEntryReadsValidateOnlyTheEntryTheyReturn(): void
    {
        $value = ['points' => ['pay' => ['http_route:A::b'], 'broken' => ['nope']], 'revisions' => ['a|latency' => 'abcdef12', 'b|latency' => '', 'c|latency' => 'nope'], 'controllers' => ['A::b' => ['pay'], 'C::d' => ['gone']], 'routes' => ['pay' => ['path' => '/pay', 'methods' => ['POST'], 'controller' => 'A::b']]] + self::valid();

        self::assertEquals([PointId::of(PointType::HttpRoute, 'A::b')], RouteIndexParser::pointsEntry($value, 'pay'));
        self::assertSame([], RouteIndexParser::pointsEntry($value, 'other'));
        self::assertNull(RouteIndexParser::pointsEntry($value, 'broken'));
        self::assertEquals(Revision::fromHex('abcdef12'), RouteIndexParser::revisionEntry($value, 'a|latency'));
        self::assertFalse(RouteIndexParser::revisionEntry($value, 'b|latency'));
        self::assertNull(RouteIndexParser::revisionEntry($value, 'c|latency'));
        self::assertNull(RouteIndexParser::revisionEntry($value, 'd|latency'));
        self::assertEquals([new RouteInfo('pay', '/pay', ['POST'], 'A::b')], RouteIndexParser::routesEntry($value, 'A::b'));
        self::assertSame([], RouteIndexParser::routesEntry($value, 'E::f'));
        self::assertNull(RouteIndexParser::routesEntry($value, 'C::d'));
        self::assertNull(RouteIndexParser::parse($value), 'the whole index is still rejected');
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function provideVersions(): iterable
    {
        yield 'current' => [['version' => RouteIndexData::VERSION], true];
        yield 'version as a string' => [['version' => (string) RouteIndexData::VERSION], false];
        yield 'no version' => [[], false];
        yield 'not an array' => [null, false];
    }

    #[DataProvider('provideVersions')]
    public function testOnlyTheCurrentVersionIsAccepted(mixed $value, bool $current): void
    {
        self::assertSame($current, RouteIndexParser::isCurrentVersion($value));
    }

    /**
     * @return array<string, mixed>
     */
    private static function valid(): array
    {
        return [
            'version' => RouteIndexData::VERSION,
            'routes' => ['pay' => ['path' => '/pay', 'methods' => ['POST'], 'controller' => 'A::b']],
            'controllers' => ['A::b' => ['pay']],
            'points' => ['pay' => ['http_route:A::b']],
            'revisions' => ['pay.create|latency' => 'abcdef12', 'pay.create|availability' => ''],
        ];
    }

    private function data(): RouteIndexData
    {
        return RouteIndexData::fromRoutes(
            [new RouteInfo('pay', '/pay', ['POST'], 'A::b'), new RouteInfo('404', '/404', [], null)],
            ['pay' => [PointId::of(PointType::HttpRoute, 'A::b')]],
            ['pay.create|latency' => 'abcdef12', 'pay.create|availability' => ''],
        );
    }
}
