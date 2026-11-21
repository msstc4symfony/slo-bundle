<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Prometheus;

use LogicException;
use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Binding\Binding;
use Msstc4Symfony\SloBundle\Application\Binding\Matcher;
use Msstc4Symfony\SloBundle\Application\Binding\SeriesBinding;
use Msstc4Symfony\SloBundle\Application\Generation\BoundObjective;
use Msstc4Symfony\SloBundle\Application\Generation\Selector;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Msstc4Symfony\SloBundle\Infrastructure\Prometheus\PromQlBuilder;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Generation\GenerationFixture;
use PHPUnit\Framework\TestCase;

final class PromQlBuilderTest extends TestCase
{
    private const HTTP_TOTAL = 'symfony_http_response{application="shop",component=~"web|worker",route=~"checkout_pay"}';

    private const HTTP_BAD = 'symfony_http_response{application="shop",component=~"web|worker",route=~"checkout_pay",status=~"5.."}';

    public function testCounterSeriesOfStrategyA(): void
    {
        $builder = new PromQlBuilder();
        $objective = $this->objective('checkout.pay', 'availability');

        self::assertSame('sum(rate(' . self::HTTP_TOTAL . '[5m]))', $builder->eventsRate(GenerationFixture::input()->selector, $objective, '5m'));
        self::assertSame(
            '(sum(rate(' . self::HTTP_BAD . '[1h])) or vector(0)) / (sum(rate(' . self::HTTP_TOTAL . '[1h])) > 0)',
            $builder->errorRatio(GenerationFixture::input()->selector, $objective, '1h'),
        );
        self::assertSame(
            '1 - ((sum(increase(' . self::HTTP_BAD . '[30d])) or vector(0)) / (sum(increase(' . self::HTTP_TOTAL . '[30d])) > 0))',
            $builder->sliRatio(GenerationFixture::input()->selector, $objective),
        );
        self::assertSame(
            '1 - (1 - slo:sli:ratio{application="shop",slo="checkout.pay",objective="availability"}) / 0.001',
            $builder->budgetRemaining($objective, 'shop'),
        );
        self::assertSame(
            'clamp((time() - min_over_time(timestamp(sum(' . self::HTTP_TOTAL . '))[721h:1h])) / 2592000, 0, 1)',
            $builder->coverageRatio(GenerationFixture::input()->selector, $objective),
        );
        self::assertSame('(sum(increase(' . self::HTTP_TOTAL . '[6h])) or vector(0)) == 0', $builder->noEvents(GenerationFixture::input()->selector, $objective, 6));
    }

    public function testDedicatedCountersFilterTheCurrentRevisionAndSumOverResult(): void
    {
        $builder = new PromQlBuilder();
        $objective = $this->objective('report.export', 'availability');
        $total = \sprintf('symfony_slo_events_total{application="shop",component=~"web|worker",slo="report.export",objective="availability",revision="%s"}', $objective->revision->value);
        $bad = substr($total, 0, -1) . ',result="bad"}';

        self::assertSame('sum(rate(' . $total . '[30m]))', $builder->eventsRate(GenerationFixture::input()->selector, $objective, '30m'));
        self::assertSame('(sum(rate(' . $bad . '[5m])) or vector(0)) / (sum(rate(' . $total . '[5m])) > 0)', $builder->errorRatio(GenerationFixture::input()->selector, $objective, '5m'));
        self::assertSame('1 - ((sum(increase(' . $bad . '[3d])) or vector(0)) / (sum(increase(' . $total . '[3d])) > 0))', $builder->sliRatio(GenerationFixture::input()->selector, $objective));
        self::assertSame('1 - (1 - slo:sli:ratio{application="shop",slo="report.export",objective="availability"}) / 0.01', $builder->budgetRemaining($objective, 'shop'));
        self::assertSame('clamp((time() - min_over_time(timestamp(sum(' . $total . '))[73h:1h])) / 259200, 0, 1)', $builder->coverageRatio(GenerationFixture::input()->selector, $objective));
    }

    public function testHistogramLatencyReadsTheBucketAgainstTheCount(): void
    {
        $builder = new PromQlBuilder();
        $objective = $this->objective('payment.process', 'latency');
        $matchers = 'application="shop",message="ProcessPayment",status="handled",transport!~"failed"';
        $count = 'symfony_messenger_message_handling_duration_histogram_seconds_count{' . $matchers . '}';
        $bucket = 'symfony_messenger_message_handling_duration_histogram_seconds_bucket{' . $matchers . ',le="1.0"}';
        $selector = new Selector('shop', []);

        self::assertSame('sum(rate(' . $count . '[5m]))', $builder->eventsRate($selector, $objective, '5m'));
        self::assertSame(
            '(sum(rate(' . $count . '[5m])) - sum(rate(' . $bucket . '[5m]))) / (sum(rate(' . $count . '[5m])) > 0)',
            $builder->errorRatio($selector, $objective, '5m'),
        );
    }

    public function testHistogramAvailabilityReadsTheCountWithTheBadMatchers(): void
    {
        $objective = $this->custom(new SeriesBinding(
            'logic_action_duration_histogram_seconds',
            SeriesBinding::KIND_HISTOGRAM,
            SeriesBinding::index([Matcher::equals('action', 'refund')]),
            SeriesBinding::index([Matcher::regex('outcome', 'success|expected_failure')]),
            SeriesBinding::index([Matcher::equals('outcome', 'error')]),
            null,
        ));

        self::assertSame(
            '(sum(rate(symfony_logic_action_duration_histogram_seconds_count{application="shop",action="refund",outcome="error"}[5m])) or vector(0)) / (sum(rate(symfony_logic_action_duration_histogram_seconds_count{application="shop",action="refund"}[5m])) > 0)',
            (new PromQlBuilder())->errorRatio(new Selector('shop', []), $objective, '5m'),
        );
    }

    public function testBadMatchersOverrideTheSameLabel(): void
    {
        $objective = $this->custom(new SeriesBinding(
            'http_response',
            SeriesBinding::KIND_COUNTER,
            SeriesBinding::index([Matcher::regex('route', 'a'), Matcher::notRegex('status', '404')]),
            [],
            SeriesBinding::index([Matcher::regex('status', '5..')]),
            null,
        ));

        self::assertStringContainsString(
            'sum(rate(symfony_http_response{application="shop",route=~"a",status=~"5.."}[5m]))',
            (new PromQlBuilder())->errorRatio(new Selector('shop', []), $objective, '5m'),
        );
    }

    public function testNoBadMatchersMeansNoBadEvents(): void
    {
        $objective = $this->custom(new SeriesBinding('http_response', SeriesBinding::KIND_COUNTER, SeriesBinding::index([Matcher::regex('route', 'a')]), [], [], null));

        self::assertSame(
            'vector(0) / (sum(rate(symfony_http_response{application="shop",route=~"a"}[5m])) > 0)',
            (new PromQlBuilder())->errorRatio(new Selector('shop', []), $objective, '5m'),
        );
    }

    public function testRecordedSeriesSelectorIsScopedToTheApplicationSloAndObjective(): void
    {
        self::assertSame(
            'slo:error_ratio:rate1h{application="shop",slo="checkout.pay",objective="availability"}',
            (new PromQlBuilder())->recorded('slo:error_ratio:rate1h', $this->objective('checkout.pay', 'availability'), 'shop'),
        );
    }

    public function testApplicationValuesAreEscaped(): void
    {
        self::assertStringContainsString(
            'application="sh\"op",component=~"a\\\\.b"',
            (new PromQlBuilder())->eventsRate(new Selector('sh"op', ['a.b']), $this->objective('checkout.pay', 'availability'), '5m'),
        );
    }

    private function objective(string $slo, string $kind): BoundObjective
    {
        $catalog = GenerationFixture::catalog();
        foreach (GenerationFixture::collector($catalog)->collect() as $bound) {
            if ($bound->definition->name()->value === $slo && $bound->objective->kind->value === $kind) {
                return $bound;
            }
        }

        throw new LogicException(\sprintf('No %s objective on %s.', $kind, $slo));
    }

    private function custom(SeriesBinding $series): BoundObjective
    {
        $definition = CatalogFixture::build(static fn ($assembler) => $assembler->declare(PointId::of(PointType::LogicAction, 'App\Refund'), new Slo('refund', [new Availability(99)])))->all()[0];

        return new BoundObjective($definition, $definition->objectives()[0], Binding::existingSeries($series), Revision::fromHex('0123abcd'));
    }
}
