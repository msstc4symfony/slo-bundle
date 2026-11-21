<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouteIndexData;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\WarmedRevisions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WarmedRevisionsTest extends TestCase
{
    /**
     * @return iterable<string, array{RouteIndexData|null, ObjectiveKind, Revision|false|null}>
     */
    public static function provideStoredRevisions(): iterable
    {
        $data = RouteIndexData::fromRoutes([], [], ['payment.create|availability' => '', 'payment.create|latency' => '0badcafe']);

        yield 'no warmed index' => [null, ObjectiveKind::Latency, null];
        yield 'reads existing series' => [$data, ObjectiveKind::Availability, false];
        yield 'dedicated' => [$data, ObjectiveKind::Latency, Revision::fromHex('0badcafe')];
        yield 'not precomputed' => [$data, ObjectiveKind::Combined, null];
    }

    #[DataProvider('provideStoredRevisions')]
    public function testRevisionOf(?RouteIndexData $data, ObjectiveKind $kind, Revision|false|null $expected): void
    {
        $revisions = new WarmedRevisions(new InMemoryRouteIndexStore($data));

        self::assertEquals($expected, $revisions->revisionOf(SloName::fromString('payment.create'), $kind));
    }
}
