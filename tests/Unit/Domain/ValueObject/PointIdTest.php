<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Domain\ValueObject;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use PHPUnit\Framework\TestCase;

final class PointIdTest extends TestCase
{
    public function testToString(): void
    {
        $id = PointId::of(PointType::HttpRoute, 'App\\Controller\\Pay::pay');

        self::assertSame(PointType::HttpRoute, $id->type);
        self::assertSame('App\\Controller\\Pay::pay', $id->id);
        self::assertSame('http_route:App\\Controller\\Pay::pay', $id->toString());
    }

    public function testRejectsEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // Reason: asserting the runtime guard against a contract violation.
        // @phpstan-ignore argument.type
        PointId::of(PointType::Operation, '');
    }

    public function testEquals(): void
    {
        self::assertTrue(PointId::of(PointType::Operation, 'x')->equals(PointId::of(PointType::Operation, 'x')));
        self::assertFalse(PointId::of(PointType::Operation, 'x')->equals(PointId::of(PointType::LogicAction, 'x')));
    }
}
