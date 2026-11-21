<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Attribute;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Combined;
use Msstc4Symfony\SloBundle\Application\Attribute\IgnoreMonitoring;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\PaymentController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class AttributesTest extends TestCase
{
    public function testRepeatedSloAttributesAreInstantiatedWithTypedObjectives(): void
    {
        $attributes = (new ReflectionMethod(PaymentController::class, 'create'))->getAttributes(Slo::class);

        self::assertCount(2, $attributes);
        $first = $attributes[0]->newInstance();
        $second = $attributes[1]->newInstance();

        self::assertSame('payment.create', $first->name);
        self::assertInstanceOf(Availability::class, $first->objectives[0]);
        self::assertInstanceOf(Latency::class, $first->objectives[1]);
        self::assertSame(['availability', 'latency'], [$first->objectives[0]->kind(), $first->objectives[1]->kind()]);
        self::assertNull($first->window);
        self::assertSame('auto', $first->strategy);

        self::assertSame('7d', $second->window);
        self::assertSame('dedicated', $second->strategy);
        self::assertInstanceOf(Combined::class, $second->objectives[0]);
        self::assertSame('combined', $second->objectives[0]->kind());
    }

    public function testObjectiveValuesAreHeldRaw(): void
    {
        $latency = new Latency('200ms', 99.5, true);

        self::assertSame('200ms', $latency->threshold);
        self::assertSame(99.5, $latency->target);
        self::assertTrue($latency->includeErrors);
        self::assertFalse((new Latency('1s', 99))->includeErrors);
        self::assertSame(99.9, (new Availability(99.9))->target);
        self::assertSame('1s', (new Combined('1s', 90))->threshold);
    }

    public function testSloOverridesDefaultToNull(): void
    {
        $slo = new Slo('a.b', [new Availability(99)], '30d', ['5xx'], [404], false, true, 'dedicated');

        self::assertSame(['5xx'], $slo->badStatuses);
        self::assertSame([404], $slo->ineligibleStatuses);
        self::assertFalse($slo->expectedFailureIsGood);
        self::assertTrue($slo->countAttempts);

        $bare = new Slo('a.b', [new Availability(99)]);
        self::assertNull($bare->badStatuses);
        self::assertNull($bare->ineligibleStatuses);
        self::assertNull($bare->expectedFailureIsGood);
        self::assertNull($bare->countAttempts);
    }

    public function testIgnoreMonitoringKeepsReason(): void
    {
        self::assertSame('health probe', (new IgnoreMonitoring('health probe'))->reason);
        $attributes = (new ReflectionMethod(PaymentController::class, 'health'))->getAttributes(IgnoreMonitoring::class);
        self::assertCount(1, $attributes);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function blankReasons(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['  '];
        yield 'whitespace' => ["\t\n"];
    }

    #[DataProvider('blankReasons')]
    public function testIgnoreMonitoringRejectsBlankReason(string $reason): void
    {
        $this->expectException(InvalidArgumentException::class);

        new IgnoreMonitoring($reason);
    }
}
