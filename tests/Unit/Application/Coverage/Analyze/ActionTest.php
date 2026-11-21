<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Coverage\Analyze;

use Closure;
use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Action;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\CoverageKind;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Input;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\Output;
use Msstc4Symfony\SloBundle\Application\Coverage\Analyze\PointCoverage;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Discovery\HandlerInfo;
use Msstc4Symfony\SloBundle\Application\Discovery\TaggedPoint;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\CoverageStatus;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsBundleSeriesCatalog;
use Msstc4Symfony\SloBundle\Test\Fixtures\Handler\AuditPaymentHandler;
use Msstc4Symfony\SloBundle\Test\Fixtures\Handler\ProcessPaymentHandler;
use Msstc4Symfony\SloBundle\Test\Fixtures\Handler\ReceiptHandler;
use Msstc4Symfony\SloBundle\Test\Fixtures\Handler\RefundPaymentHandler;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\InMemoryRouteCatalog;
use PHPUnit\Framework\TestCase;

final class ActionTest extends TestCase
{
    public function testHttpRoutesAreClassifiedAndPercentIgnoresIgnoredPoints(): void
    {
        $output = $this->analyse(
            static function (CatalogAssembler $assembler): void {
                $assembler->declare(self::http('A::slo'), new Slo('a.slo', [new Availability(99.9)]));
                $assembler->ignore(self::http('A::ignored'), 'internal tool');
            },
            [
                new RouteInfo('route_slo', '/a', ['GET'], 'A::slo'),
                new RouteInfo('route_ignored', '/b', ['GET'], 'A::ignored'),
                new RouteInfo('_profiler', '/_profiler', ['GET'], 'Profiler::panel'),
                new RouteInfo('route_plain', '/c', ['GET'], 'A::plain'),
            ],
        );

        $byPoint = $this->byPoint($output, CoverageKind::HttpRoute);
        self::assertSame(CoverageStatus::Monitored, $byPoint['route_slo']->status);
        self::assertSame(['a.slo'], $byPoint['route_slo']->slos);
        self::assertNull($byPoint['route_slo']->reason);
        self::assertTrue($byPoint['route_slo']->measuredByUniversalMetric);
        self::assertSame(CoverageStatus::Ignored, $byPoint['route_ignored']->status);
        self::assertSame('internal tool', $byPoint['route_ignored']->reason);
        self::assertSame(CoverageStatus::Ignored, $byPoint['_profiler']->status);
        self::assertSame('system route', $byPoint['_profiler']->reason);
        self::assertFalse($byPoint['_profiler']->measuredByUniversalMetric);
        self::assertSame(CoverageStatus::Unmonitored, $byPoint['route_plain']->status);
        self::assertSame(50.0, $output->of(CoverageKind::HttpRoute)->sloPercent);
        self::assertSame(75.0, $output->of(CoverageKind::HttpRoute)->measuredPercent);
        self::assertSame(0, $output->invalid);
    }

    public function testConfiguredIgnoredRoutesAndMetricsBundleIgnoredRoutes(): void
    {
        $output = $this->analyse(
            static function (CatalogAssembler $assembler): void {
            },
            [
                new RouteInfo('configured', '/a', ['GET'], 'A::a'),
                new RouteInfo('health', '/health', ['GET'], 'A::health'),
            ],
            new CatalogExtras(httpIgnoredRoutes: ['health']),
            new Input(['configured']),
        );

        $byPoint = $this->byPoint($output, CoverageKind::HttpRoute);
        self::assertSame('configured in coverage.http_routes.ignored_routes', $byPoint['configured']->reason);
        self::assertSame(CoverageStatus::Ignored, $byPoint['health']->status);
        self::assertSame('system route', $byPoint['health']->reason);
        self::assertFalse($byPoint['health']->measuredByUniversalMetric);
        self::assertTrue($byPoint['configured']->measuredByUniversalMetric);
        self::assertSame(100.0, $output->of(CoverageKind::HttpRoute)->sloPercent);
    }

    public function testSloOnRouteIgnoredByMetricsBundleIsInvalid(): void
    {
        $output = $this->analyse(
            static function (CatalogAssembler $assembler): void {
                $assembler->declare(self::http('A::slo'), new Slo('a.slo', [new Availability(99.9)]));
            },
            [new RouteInfo('health', '/health', ['GET'], 'A::slo')],
            new CatalogExtras(httpIgnoredRoutes: ['health']),
        );

        $point = $this->byPoint($output, CoverageKind::HttpRoute)['health'];
        self::assertSame(CoverageStatus::Invalid, $point->status);
        self::assertSame(['a.slo'], $point->slos);
        self::assertSame(1, $output->invalid);
        self::assertSame(0.0, $output->of(CoverageKind::HttpRoute)->sloPercent);
    }

    public function testConflictingDeclarationsMakeEveryTouchedRouteInvalid(): void
    {
        $output = $this->analyse(
            static function (CatalogAssembler $assembler): void {
                $assembler->declare(self::http('A::one'), new Slo('a.same', [new Availability(99.9)]));
                $assembler->declare(self::http('A::two'), new Slo('a.same', [new Availability(99.0)]));
            },
            [
                new RouteInfo('one', '/1', ['GET'], 'A::one'),
                new RouteInfo('two', '/2', ['GET'], 'A::two'),
            ],
        );

        $byPoint = $this->byPoint($output, CoverageKind::HttpRoute);
        self::assertSame(CoverageStatus::Invalid, $byPoint['one']->status);
        self::assertSame(CoverageStatus::Invalid, $byPoint['two']->status);
        self::assertSame('SLO a.same is declared differently on several points', $byPoint['one']->reason);
        self::assertSame(2, $output->invalid);
    }

    public function testRouteWithoutResolvableControllerIsUnmonitored(): void
    {
        $output = $this->analyse(static function (CatalogAssembler $assembler): void {
        }, [new RouteInfo('closure', '/x', [], null)]);

        self::assertSame(CoverageStatus::Unmonitored, $this->byPoint($output, CoverageKind::HttpRoute)['closure']->status);
    }

    public function testSharedSloAppearsOnEveryRoute(): void
    {
        $output = $this->analyse(
            static function (CatalogAssembler $assembler): void {
                $assembler->declare(self::http('A::one'), new Slo('a.shared', [new Availability(99.9)]));
                $assembler->declare(self::http('A::two'), new Slo('a.shared', [new Availability(99.9)]));
            },
            [
                new RouteInfo('one', '/1', ['GET'], 'A::one'),
                new RouteInfo('two', '/2', ['GET'], 'A::two'),
            ],
        );

        foreach ($this->byPoint($output, CoverageKind::HttpRoute) as $point) {
            self::assertSame(CoverageStatus::Monitored, $point->status);
            self::assertSame(['a.shared'], $point->slos);
        }
    }

    public function testMessengerHandlersAreMeasuredWhenAsyncAndSyncIsUnsupported(): void
    {
        $output = $this->analyse(
            static function (CatalogAssembler $assembler): void {
                $assembler->declare(PointId::of(PointType::MessengerMessage, 'App\Pay'), new Slo('pay.handle', [new Availability(99)]));
                $assembler->ignore(PointId::of(PointType::MessengerMessage, 'App\Audit'), 'best effort');
            },
            [],
            new CatalogExtras(handlers: [
                new HandlerInfo(ProcessPaymentHandler::class, 'App\Pay', true, 'async', '__invoke'),
                new HandlerInfo(RefundPaymentHandler::class, 'App\Sync', false, null, 'handle'),
                new HandlerInfo(AuditPaymentHandler::class, 'App\Audit', true, 'async', '__invoke'),
                new HandlerInfo(ReceiptHandler::class, 'App\Receipt', true, 'async', 'onReceipt'),
            ]),
        );

        $byPoint = $this->byPoint($output, CoverageKind::MessengerHandler);
        $pay = $byPoint[ProcessPaymentHandler::class . '::__invoke'];
        self::assertSame(CoverageStatus::Monitored, $pay->status);
        self::assertSame(['pay.handle'], $pay->slos);
        self::assertTrue($pay->measuredByUniversalMetric);
        $sync = $byPoint[RefundPaymentHandler::class . '::handle'];
        self::assertSame(CoverageStatus::Unsupported, $sync->status);
        self::assertSame('sync handling is not measured', $sync->reason);
        self::assertFalse($sync->measuredByUniversalMetric);
        $audit = $byPoint[AuditPaymentHandler::class . '::__invoke'];
        self::assertSame(CoverageStatus::Ignored, $audit->status);
        self::assertSame('best effort', $audit->reason);
        self::assertSame(CoverageStatus::Unmonitored, $byPoint[ReceiptHandler::class . '::onReceipt']->status);
        self::assertSame(50.0, $output->of(CoverageKind::MessengerHandler)->sloPercent);
        self::assertSame(75.0, $output->of(CoverageKind::MessengerHandler)->measuredPercent);
    }

    public function testMessengerConflictIsInvalid(): void
    {
        $message = PointId::of(PointType::MessengerMessage, 'App\Pay');
        $output = $this->analyse(
            static function (CatalogAssembler $assembler) use ($message): void {
                $assembler->declare($message, new Slo('pay.same', [new Availability(99)]));
                $assembler->declare($message, new Slo('pay.same', [new Availability(99)]));
            },
            [],
            new CatalogExtras(handlers: [new HandlerInfo(ProcessPaymentHandler::class, 'App\Pay', true, 'async', '__invoke')]),
        );

        self::assertSame(CoverageStatus::Invalid, $this->byPoint($output, CoverageKind::MessengerHandler)[ProcessPaymentHandler::class . '::__invoke']->status);
        self::assertSame(1, $output->invalid);
    }

    public function testLogicActionsAreMeasuredOnlyWithALogicBucketDefinition(): void
    {
        $cleanup = PointId::of(PointType::LogicAction, 'cleanup');
        $refund = PointId::of(PointType::LogicAction, 'refund');
        $declare = static function (CatalogAssembler $assembler) use ($refund, $cleanup): void {
            $assembler->declare($refund, new Slo('refund.run', [new Availability(99)]));
            $assembler->ignore($cleanup, 'cron only');
        };
        $tagged = [new TaggedPoint($cleanup, 'cleanup'), new TaggedPoint($refund, 'refund'), new TaggedPoint(PointId::of(PointType::LogicAction, 'sync'), null)];

        $without = $this->analyse($declare, [], new CatalogExtras(taggedPoints: $tagged));
        $with = $this->analyse($declare, [], new CatalogExtras(taggedPoints: $tagged, metricBuckets: ['logic_action_duration_histogram_seconds' => [0.1]]));

        $byPoint = $this->byPoint($with, CoverageKind::LogicAction);
        self::assertSame(CoverageStatus::Monitored, $byPoint['refund']->status);
        self::assertSame(CoverageStatus::Ignored, $byPoint['cleanup']->status);
        self::assertSame(CoverageStatus::Unmonitored, $byPoint['sync']->status);
        self::assertTrue($byPoint['refund']->measuredByUniversalMetric);
        self::assertFalse($this->byPoint($without, CoverageKind::LogicAction)['refund']->measuredByUniversalMetric);
        self::assertSame(50.0, $with->of(CoverageKind::LogicAction)->sloPercent);
    }

    public function testOperationsAreMonitoredAndDedicatedOnly(): void
    {
        $output = $this->analyse(static function (CatalogAssembler $assembler): void {
            $assembler->declare(PointId::of(PointType::Operation, 'export'), new Slo('export', [new Availability(99)]));
        }, [], new CatalogExtras(operations: ['export']));

        $point = $this->byPoint($output, CoverageKind::Operation)['export'];
        self::assertSame(CoverageStatus::Monitored, $point->status);
        self::assertSame(['export'], $point->slos);
        self::assertFalse($point->measuredByUniversalMetric);
        self::assertSame(100.0, $output->of(CoverageKind::Operation)->sloPercent);
        self::assertSame(0.0, $output->of(CoverageKind::Operation)->measuredPercent);
    }

    public function testEmptyDenominatorIsOneHundredPercent(): void
    {
        $output = $this->analyse(static function (CatalogAssembler $assembler): void {
        }, []);

        self::assertSame([], $output->points);
        foreach ([CoverageKind::HttpRoute, CoverageKind::MessengerHandler, CoverageKind::LogicAction, CoverageKind::Operation] as $kind) {
            self::assertSame(100.0, $output->of($kind)->sloPercent);
            self::assertSame(100.0, $output->of($kind)->measuredPercent);
        }
    }

    public function testPercentIsRoundedToTwoDecimals(): void
    {
        $routes = [];
        for ($i = 0; $i < 3; $i++) {
            $routes[] = new RouteInfo('r' . $i, '/' . $i, ['GET'], 'A::m' . $i);
        }

        $output = $this->analyse(static function (CatalogAssembler $assembler): void {
            $assembler->declare(self::http('A::m0'), new Slo('a.m0', [new Availability(99.9)]));
        }, $routes);

        self::assertSame(33.33, $output->of(CoverageKind::HttpRoute)->sloPercent);
    }

    public function testPointsAreSortedByKindThenPoint(): void
    {
        $output = $this->analyse(
            static function (CatalogAssembler $assembler): void {
            },
            [new RouteInfo('b', '/b', [], 'A::b'), new RouteInfo('a', '/a', [], 'A::a')],
            new CatalogExtras(operations: ['zeta', 'alpha']),
        );

        self::assertSame(['a', 'b', 'alpha', 'zeta'], array_map(static fn (PointCoverage $p): string => $p->point, $output->points));
    }

    public function testBelowThresholdListsKindsUnderTheirRequiredPercent(): void
    {
        $output = $this->analyse(
            static function (CatalogAssembler $assembler): void {
                $assembler->declare(self::http('A::slo'), new Slo('a.slo', [new Availability(99.9)]));
            },
            [new RouteInfo('a', '/a', [], 'A::slo'), new RouteInfo('b', '/b', [], 'A::b')],
        );

        self::assertSame([CoverageKind::HttpRoute], $output->belowThreshold([CoverageKind::HttpRoute->value => 95.0, CoverageKind::MessengerHandler->value => 95.0]));
        self::assertSame([], $output->belowThreshold([CoverageKind::HttpRoute->value => 50.0]));
    }

    /**
     * @param callable(CatalogAssembler): void $declare
     * @param list<RouteInfo> $routes
     */
    private function analyse(callable $declare, array $routes, ?CatalogExtras $extras = null, ?Input $input = null): Output
    {
        $catalog = CatalogFixture::build(Closure::fromCallable($declare), $extras);

        return (new Action($catalog, $catalog, new InMemoryRouteCatalog($routes), new MetricsBundleSeriesCatalog()))($input ?? new Input([]));
    }

    /**
     * @return array<string, PointCoverage>
     */
    private function byPoint(Output $output, CoverageKind $kind): array
    {
        $result = [];
        foreach ($output->points as $point) {
            if ($point->kind === $kind) {
                $result[$point->point] = $point;
            }
        }

        return $result;
    }

    /**
     * @param non-empty-string $controller
     */
    private static function http(string $controller): PointId
    {
        return PointId::of(PointType::HttpRoute, $controller);
    }
}
