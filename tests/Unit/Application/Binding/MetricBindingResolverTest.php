<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Binding;

use InvalidArgumentException;
use LogicException;
use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Combined;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\ObjectiveAttributeInterface;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Binding\Binding;
use Msstc4Symfony\SloBundle\Application\Binding\Matcher;
use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Binding\SeriesBinding;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Discovery\HandlerInfo;
use Msstc4Symfony\SloBundle\Application\Discovery\TaggedPoint;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Entity\SloDefinition;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Enum\Strategy;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Objective;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Target;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\InMemoryRouteCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class MetricBindingResolverTest extends TestCase
{
    private const HTTP_BUCKETS = [0.005, 0.01, 0.05, 0.1, 0.25, 0.5, 1.0, 2.5];

    /**
     * @return iterable<string, array{PointType, Slo, ObjectiveKind, Strategy}>
     */
    public static function provideRows(): iterable
    {
        $http = PointType::HttpRoute;
        $message = PointType::MessengerMessage;
        $logic = PointType::LogicAction;

        yield 'http availability defaults' => [$http, self::slo(new Availability(99.9)), ObjectiveKind::Availability, Strategy::ExistingSeries];
        yield 'http availability custom statuses' => [$http, self::slo(new Availability(99.9), badStatuses: [429, '5xx'], ineligibleStatuses: [404]), ObjectiveKind::Availability, Strategy::ExistingSeries];
        yield 'http availability dedicated' => [$http, self::slo(new Availability(99.9), strategy: StrategyPreference::Dedicated), ObjectiveKind::Availability, Strategy::Dedicated];
        yield 'http latency 200ms has no bucket' => [$http, self::slo(new Latency('200ms', 99, true)), ObjectiveKind::Latency, Strategy::Dedicated];
        yield 'http latency 500ms with includeErrors' => [$http, self::slo(new Latency('500ms', 99, true)), ObjectiveKind::Latency, Strategy::ExistingSeries];
        yield 'http latency 0.5s with includeErrors' => [$http, self::slo(new Latency('0.5s', 99, true)), ObjectiveKind::Latency, Strategy::ExistingSeries];
        yield 'http latency 500ms without includeErrors' => [$http, self::slo(new Latency('500ms', 99)), ObjectiveKind::Latency, Strategy::Dedicated];
        yield 'http latency includeErrors but ineligible statuses' => [$http, self::slo(new Latency('500ms', 99, true), ineligibleStatuses: [404]), ObjectiveKind::Latency, Strategy::Dedicated];
        yield 'http latency dedicated' => [$http, self::slo(new Latency('500ms', 99, true), strategy: StrategyPreference::Dedicated), ObjectiveKind::Latency, Strategy::Dedicated];
        yield 'http combined' => [$http, self::slo(new Combined('500ms', 99)), ObjectiveKind::Combined, Strategy::Dedicated];
        yield 'messenger availability defaults' => [$message, self::slo(new Availability(99)), ObjectiveKind::Availability, Strategy::ExistingSeries];
        yield 'messenger availability countAttempts' => [$message, self::slo(new Availability(99), countAttempts: true), ObjectiveKind::Availability, Strategy::Dedicated];
        yield 'messenger latency bucket' => [$message, self::slo(new Latency('1s', 99)), ObjectiveKind::Latency, Strategy::ExistingSeries];
        yield 'messenger latency no bucket' => [$message, self::slo(new Latency('300ms', 99)), ObjectiveKind::Latency, Strategy::Dedicated];
        yield 'messenger latency countAttempts' => [$message, self::slo(new Latency('1s', 99), countAttempts: true), ObjectiveKind::Latency, Strategy::Dedicated];
        yield 'messenger combined' => [$message, self::slo(new Combined('1s', 99)), ObjectiveKind::Combined, Strategy::Dedicated];
        yield 'logic availability defaults' => [$logic, self::slo(new Availability(99)), ObjectiveKind::Availability, Strategy::ExistingSeries];
        yield 'logic availability expected failure is bad' => [$logic, self::slo(new Availability(99), expectedFailureIsGood: false), ObjectiveKind::Availability, Strategy::Dedicated];
        yield 'logic latency bucket' => [$logic, self::slo(new Latency('1s', 99)), ObjectiveKind::Latency, Strategy::ExistingSeries];
        yield 'logic latency no bucket' => [$logic, self::slo(new Latency('300ms', 99)), ObjectiveKind::Latency, Strategy::Dedicated];
        yield 'logic latency expected failure is bad' => [$logic, self::slo(new Latency('1s', 99), expectedFailureIsGood: false), ObjectiveKind::Latency, Strategy::ExistingSeries];
        yield 'logic combined' => [$logic, self::slo(new Combined('1s', 99)), ObjectiveKind::Combined, Strategy::Dedicated];
        yield 'operation availability' => [PointType::Operation, self::slo(new Availability(99)), ObjectiveKind::Availability, Strategy::Dedicated];
    }

    #[DataProvider('provideRows')]
    public function testStrategyPerRow(PointType $type, Slo $slo, ObjectiveKind $kind, Strategy $expected): void
    {
        $definition = $this->define($type, $slo);
        $resolver = $this->resolver($definition, $this->extras());

        $binding = $resolver->resolve($definition, $this->objective($definition, $kind));

        self::assertSame($expected, $binding->strategy);
        self::assertSame($expected === Strategy::ExistingSeries, $binding->series instanceof SeriesBinding);
        self::assertSame($expected === Strategy::Dedicated, $resolver->isDedicated($definition, $kind));
    }

    public function testLogicWithoutBucketDefinitionIsDedicated(): void
    {
        $extras = $this->extras(buckets: ['request_duration_histogram_seconds' => self::HTTP_BUCKETS]);
        foreach ([new Availability(99), new Latency('1s', 99)] as $objective) {
            $definition = $this->define(PointType::LogicAction, self::slo($objective));

            self::assertSame(Strategy::Dedicated, $this->resolver($definition, $extras)->resolve($definition, $definition->objectives()[0])->strategy);
        }
    }

    public function testLogicPointWithoutMetricLabelIsDedicated(): void
    {
        $definition = $this->define(PointType::LogicAction, self::slo(new Availability(99)));
        $extras = new CatalogExtras(metricBuckets: ['logic_action_duration_histogram_seconds' => [1.0]]);

        self::assertSame(Strategy::Dedicated, $this->resolver($definition, $extras)->resolve($definition, $definition->objectives()[0])->strategy);
    }

    public function testHttpWithoutRoutesIsDedicated(): void
    {
        $definition = $this->define(PointType::HttpRoute, self::slo(new Availability(99.9)));
        $resolver = new MetricBindingResolver(new InMemoryRouteCatalog(), CatalogFixture::build(static fn (): null => null, $this->extras()));

        self::assertSame(Strategy::Dedicated, $resolver->resolve($definition, $definition->objectives()[0])->strategy);
    }

    public function testBucketMatchIsAnIntegerMillisecondCompare(): void
    {
        $extras = $this->extras(buckets: ['request_duration_histogram_seconds' => [0.1 + 0.2, 0.7]]);
        $definition = $this->define(PointType::HttpRoute, self::slo(new Latency('300ms', 99, true)));

        $binding = $this->resolver($definition, $extras)->resolve($definition, $definition->objectives()[0]);

        self::assertSame('0.3', $this->series($binding)->le);
    }

    public function testIntegralBucketRendersPrometheus3LeLabel(): void
    {
        $definition = $this->define(PointType::MessengerMessage, self::slo(new Latency('1s', 99)));

        $binding = $this->resolver($definition, $this->extras())->resolve($definition, $definition->objectives()[0]);

        self::assertSame('1.0', $this->series($binding)->le);
    }

    public function testNearBucketDoesNotMatchTheThreshold(): void
    {
        $extras = $this->extras(buckets: ['request_duration_histogram_seconds' => [0.2504, 0.5]]);
        $definition = $this->define(PointType::HttpRoute, self::slo(new Latency('250ms', 99, true)));

        $binding = $this->resolver($definition, $extras)->resolve($definition, $definition->objectives()[0]);

        self::assertSame(Strategy::Dedicated, $binding->strategy);
        self::assertTrue($this->resolver($definition, $extras)->thresholdMissesBucket($definition, $definition->objectives()[0]));
    }

    public function testHttpAvailabilitySeries(): void
    {
        $definition = $this->define(PointType::HttpRoute, self::slo(new Availability(99.9), badStatuses: [429, '5xx'], ineligibleStatuses: [404]));

        $series = $this->series($this->resolver($definition, $this->extras())->resolve($definition, $definition->objectives()[0]));

        self::assertSame('http_response', $series->metric);
        self::assertSame('counter', $series->kind);
        self::assertNull($series->le);
        self::assertSame(['route=~"payment\\\\.create|payment_update"', 'status!~"404"'], $this->rendered($series->matchers));
        self::assertSame(['status=~"429|5.."'], $this->rendered($series->badMatchers));
        self::assertSame(['status!~"429|5..|404"'], $this->rendered($series->goodMatchers));
    }

    public function testHttpAvailabilityDefaultsOmitIneligibleMatcher(): void
    {
        $definition = $this->define(PointType::HttpRoute, self::slo(new Availability(99.9)));

        $series = $this->series($this->resolver($definition, $this->extras())->resolve($definition, $definition->objectives()[0]));

        self::assertSame(['route=~"payment\\\\.create|payment_update"'], $this->rendered($series->matchers));
        self::assertSame(['status=~"5.."'], $this->rendered($series->badMatchers));
        self::assertSame(['status!~"5.."'], $this->rendered($series->goodMatchers));
    }

    public function testHttpLatencySeries(): void
    {
        $definition = $this->define(PointType::HttpRoute, self::slo(new Latency('500ms', 99, true)));

        $series = $this->series($this->resolver($definition, $this->extras())->resolve($definition, $definition->objectives()[0]));

        self::assertSame('request_duration_histogram_seconds', $series->metric);
        self::assertSame('histogram', $series->kind);
        self::assertSame('0.5', $series->le);
        self::assertSame(['route=~"payment\\\\.create|payment_update"'], $this->rendered($series->matchers));
        self::assertSame([], $series->badMatchers);
        self::assertSame([], $series->goodMatchers);
    }

    public function testMessengerAvailabilitySeries(): void
    {
        $definition = $this->define(PointType::MessengerMessage, self::slo(new Availability(99)));

        $series = $this->series($this->resolver($definition, $this->extras(failureTransports: ['failed', 'dead.letter']))->resolve($definition, $definition->objectives()[0]));

        self::assertSame('messenger_message_handled', $series->metric);
        self::assertSame('counter', $series->kind);
        self::assertSame(['message="ProcessPayment"', 'status=~"handled|failed"', 'transport!~"dead\\\\.letter|failed"'], $this->rendered($series->matchers));
        self::assertSame(['status="failed"'], $this->rendered($series->badMatchers));
        self::assertSame(['status="handled"'], $this->rendered($series->goodMatchers));
    }

    public function testMessengerWithoutFailureTransportsOmitsTransportMatcher(): void
    {
        $definition = $this->define(PointType::MessengerMessage, self::slo(new Availability(99)));

        $series = $this->series($this->resolver($definition, $this->extras())->resolve($definition, $definition->objectives()[0]));

        self::assertSame(['message="ProcessPayment"', 'status=~"handled|failed"'], $this->rendered($series->matchers));
    }

    public function testMessengerShortNameSharedWithAnotherHandledMessageForcesDedicated(): void
    {
        $definition = $this->define(PointType::MessengerMessage, self::slo(new Availability(99), new Latency('1s', 99)));
        $handlers = [
            new HandlerInfo(stdClass::class, 'App\Message\ProcessPayment', true, null),
            new HandlerInfo(stdClass::class, 'App\Legacy\ProcessPayment', true, null),
        ];
        $resolver = $this->resolver($definition, new CatalogExtras(handlers: $handlers, metricBuckets: ['messenger_message_handling_duration_histogram_seconds' => [1.0]]));

        self::assertTrue($resolver->isDedicated($definition, ObjectiveKind::Availability));
        self::assertTrue($resolver->isDedicated($definition, ObjectiveKind::Latency));
    }

    public function testMessengerOwnHandlersDoNotForceDedicated(): void
    {
        $definition = $this->define(PointType::MessengerMessage, self::slo(new Availability(99)));
        $handlers = [
            new HandlerInfo(stdClass::class, 'App\Message\ProcessPayment', true, null),
            new HandlerInfo(stdClass::class, 'App\Message\ProcessPayment', true, null, 'audit'),
            new HandlerInfo(stdClass::class, 'App\Message\RefundPayment', true, null),
        ];

        self::assertFalse($this->resolver($definition, new CatalogExtras(handlers: $handlers))->isDedicated($definition, ObjectiveKind::Availability));
    }

    public function testMessengerLatencyWithIncludeErrorsFallsBackToDedicated(): void
    {
        $compiled = $this->define(PointType::MessengerMessage, self::slo(new Latency('1s', 99)));
        $threshold = $this->objective($compiled, ObjectiveKind::Latency)->threshold ?? throw new LogicException('threshold missing');
        $definition = new SloDefinition(
            $compiled->name(),
            PointType::MessengerMessage,
            $compiled->points(),
            [Objective::latency($threshold, Target::fromPercent(99), true)],
            $compiled->window(),
            $compiled->classification(),
            $compiled->strategy(),
        );

        self::assertTrue($this->resolver($definition, $this->extras())->isDedicated($definition, ObjectiveKind::Latency));
    }

    public function testMessengerLatencySeries(): void
    {
        $definition = $this->define(PointType::MessengerMessage, self::slo(new Latency('1s', 99)));

        $series = $this->series($this->resolver($definition, $this->extras(failureTransports: ['failed']))->resolve($definition, $definition->objectives()[0]));

        self::assertSame('messenger_message_handling_duration_histogram_seconds', $series->metric);
        self::assertSame('histogram', $series->kind);
        self::assertSame('1.0', $series->le);
        self::assertSame(['message="ProcessPayment"', 'status="handled"', 'transport!~"failed"'], $this->rendered($series->matchers));
    }

    public function testLogicSeries(): void
    {
        $definition = $this->define(PointType::LogicAction, self::slo(new Availability(99), new Latency('250ms', 99)));
        $resolver = $this->resolver($definition, $this->extras());

        $availability = $this->series($resolver->resolve($definition, $this->objective($definition, ObjectiveKind::Availability)));
        $latency = $this->series($resolver->resolve($definition, $this->objective($definition, ObjectiveKind::Latency)));

        self::assertSame('logic_action_duration_histogram_seconds', $availability->metric);
        self::assertSame('histogram', $availability->kind);
        self::assertNull($availability->le);
        self::assertSame(['action="refund"'], $this->rendered($availability->matchers));
        self::assertSame(['outcome="error"'], $this->rendered($availability->badMatchers));
        self::assertSame(['outcome=~"success|expected_failure"'], $this->rendered($availability->goodMatchers));
        self::assertSame('0.25', $latency->le);
        self::assertSame(['action="refund"', 'outcome=~"success|expected_failure"'], $this->rendered($latency->matchers));
    }

    public function testIsDedicatedRejectsAnUndeclaredKind(): void
    {
        $definition = $this->define(PointType::HttpRoute, self::slo(new Availability(99.9)));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('latency');

        $this->resolver($definition, $this->extras())->isDedicated($definition, ObjectiveKind::Latency);
    }

    /**
     * @param list<int|string>|null $badStatuses
     * @param list<int|string>|null $ineligibleStatuses
     */
    private static function slo(ObjectiveAttributeInterface $first, ?ObjectiveAttributeInterface $second = null, ?array $badStatuses = null, ?array $ineligibleStatuses = null, ?bool $expectedFailureIsGood = null, ?bool $countAttempts = null, StrategyPreference $strategy = StrategyPreference::Auto): Slo
    {
        return new Slo('payment.create', $second instanceof ObjectiveAttributeInterface ? [$first, $second] : [$first], null, $badStatuses, $ineligibleStatuses, $expectedFailureIsGood, $countAttempts, $strategy);
    }

    private function point(PointType $type): PointId
    {
        return match ($type) {
            PointType::HttpRoute => PointId::of($type, 'App\PaymentController::create'),
            PointType::MessengerMessage => PointId::of($type, 'App\Message\ProcessPayment'),
            PointType::LogicAction => PointId::of($type, 'App\Action\RefundAction'),
            PointType::Operation => PointId::of($type, 'payment.validation'),
        };
    }

    private function define(PointType $type, Slo $slo): SloDefinition
    {
        $catalog = CatalogFixture::build(fn ($assembler) => $assembler->declare($this->point($type), $slo));

        return $catalog->all()[0];
    }

    private function objective(SloDefinition $definition, ObjectiveKind $kind): Objective
    {
        return $definition->objective($kind) ?? throw new LogicException('objective missing');
    }

    /**
     * @param array<non-empty-string, list<float>>|null $buckets
     * @param list<non-empty-string> $failureTransports
     */
    private function extras(?array $buckets = null, array $failureTransports = []): CatalogExtras
    {
        return new CatalogExtras(
            taggedPoints: [new TaggedPoint(PointId::of(PointType::LogicAction, 'App\Action\RefundAction'), 'refund')],
            failureTransports: $failureTransports,
            metricBuckets: $buckets ?? [
                'request_duration_histogram_seconds' => self::HTTP_BUCKETS,
                'messenger_message_handling_duration_histogram_seconds' => [0.1, 0.5, 1.0, 5.0],
                'logic_action_duration_histogram_seconds' => [0.01, 0.1, 0.25, 1.0],
            ],
        );
    }

    private function resolver(SloDefinition $definition, CatalogExtras $extras): MetricBindingResolver
    {
        $routes = [];
        foreach ($definition->points() as $point) {
            $routes[] = new RouteInfo('payment_update', '/p/{id}', ['PUT'], $point->id);
            $routes[] = new RouteInfo('payment.create', '/p', ['POST'], $point->id);
        }

        return new MetricBindingResolver(new InMemoryRouteCatalog($routes), CatalogFixture::build(static fn (): null => null, $extras));
    }

    private function series(Binding $binding): SeriesBinding
    {
        self::assertNotNull($binding->series);

        return $binding->series;
    }

    /**
     * @param array<string, Matcher> $matchers
     *
     * @return list<string>
     */
    private function rendered(array $matchers): array
    {
        self::assertSame(array_keys($matchers), array_map(static fn (Matcher $m): string => $m->label, array_values($matchers)));
        $keys = array_keys($matchers);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $keys);

        return array_values(array_map(static fn (Matcher $m): string => $m->toPromQl(), $matchers));
    }
}
