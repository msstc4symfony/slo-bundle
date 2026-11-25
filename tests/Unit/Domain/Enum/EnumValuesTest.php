<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Domain\Enum;

use BackedEnum;
use Msstc4Symfony\SloBundle\Application\Binding\Strategy;
use Msstc4Symfony\SloBundle\Domain\Enum\DeliveryStatus;
use Msstc4Symfony\SloBundle\Domain\Enum\EventResult;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\Outcome;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EnumValuesTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<BackedEnum>, list<string>}>
     */
    public static function provideEnums(): iterable
    {
        yield 'kind' => [ObjectiveKind::class, ['availability', 'latency', 'combined']];
        yield 'point type' => [PointType::class, ['http_route', 'messenger_message', 'logic_action', 'operation', 'scheduled_task']];
        yield 'outcome' => [Outcome::class, ['success', 'expected_failure', 'error']];
        yield 'delivery' => [DeliveryStatus::class, ['handled', 'failed', 'retried']];
        yield 'result' => [EventResult::class, ['good', 'bad']];
        yield 'strategy' => [StrategyPreference::class, ['auto', 'dedicated']];
        yield 'binding strategy' => [Strategy::class, ['existing_series', 'dedicated']];
    }

    /**
     * @param class-string<BackedEnum> $enum
     * @param list<string> $values
     */
    #[DataProvider('provideEnums')]
    public function testValues(string $enum, array $values): void
    {
        self::assertSame($values, array_map(static fn (BackedEnum $case): int|string => $case->value, $enum::cases()));
    }
}
