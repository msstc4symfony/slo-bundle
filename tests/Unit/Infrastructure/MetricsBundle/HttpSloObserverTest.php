<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\MetricsBundle;

use Msstc4Symfony\MetricsBundle\Application\Port\HttpMeasurement;
use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Combined;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Binding\RevisionResolver;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Action;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Application\Port\RoutePointIndexInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Service\EventClassifier;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\HttpSloObserver;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsBundleSeriesCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouteIndexFile;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RoutePointIndex;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouterRouteCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouterRouteReader;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\FailingCatalog;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\InMemoryRouteCatalog;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Evaluation\RecordEvent\FixedPrecomputedRevisions;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Evaluation\RecordEvent\InMemoryEventWriter;
use Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony\RecordingLogger;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Routing\RouterInterface;

final class HttpSloObserverTest extends TestCase
{
    private const CONTROLLER = 'App\PaymentController::create';

    private CompiledSloCatalog $catalog;

    private InMemoryEventWriter $writer;

    private RecordingLogger $logger;

    private HttpSloObserver $observer;

    #[Override]
    protected function setUp(): void
    {
        $this->catalog = CatalogFixture::build(static function (CatalogAssembler $assembler): void {
            $assembler->declare(PointId::of(PointType::HttpRoute, self::CONTROLLER), new Slo('payment.create', [new Combined('200ms', 99), new Latency('200ms', 99)]));
        });
        $catalog = $this->catalog;
        $routes = new InMemoryRouteCatalog([
            new RouteInfo('payment_create', '/payments', ['POST'], self::CONTROLLER),
            new RouteInfo('payment_list', '/payments', ['GET'], 'App\PaymentController::list'),
        ]);
        $this->writer = new InMemoryEventWriter();
        $this->logger = new RecordingLogger();
        $this->observer = new HttpSloObserver(
            new RoutePointIndex($routes, $catalog, new RouteIndexFile(sys_get_temp_dir() . '/msstc4symfony-slo-no-route-index', false), $this->logger),
            $catalog,
            ObserverFixture::action($catalog, $routes, $this->writer, $this->logger),
            $this->logger,
        );
    }

    /**
     * @return iterable<string, array{int<100, 599>, float, list<string>}>
     */
    public static function classificationProvider(): iterable
    {
        yield 'fast 200' => [200, 0.05, ['combined:good', 'latency:good']];
        yield 'threshold is inclusive' => [200, 0.2, ['combined:good', 'latency:good']];
        yield 'slow 200' => [200, 0.35, ['combined:bad', 'latency:bad']];
        yield 'fast 400 is a client error' => [400, 0.05, ['combined:good', 'latency:good']];
        yield 'slow 404' => [404, 0.35, ['combined:bad', 'latency:bad']];
        yield 'fast 302' => [302, 0.05, ['combined:good', 'latency:good']];
        yield 'fast 500 is bad and latency-ineligible' => [500, 0.05, ['combined:bad']];
        yield 'slow 503' => [503, 0.35, ['combined:bad']];
    }

    /**
     * @param int<100, 599> $status
     * @param list<string> $expected
     */
    #[DataProvider('classificationProvider')]
    public function testClassifiesTheMeasurement(int $status, float $duration, array $expected): void
    {
        $this->observer->onHttpMeasured(new HttpMeasurement('POST', 'payment_create', $status, $duration, null));

        self::assertSame($expected, ObserverFixture::results($this->writer));
        self::assertSame([], $this->logger->records);
    }

    public function testUncaughtExceptionIsClassifiedByStatusOnce(): void
    {
        $this->observer->onHttpMeasured(new HttpMeasurement('POST', 'payment_create', 500, 0.05, new RuntimeException('boom')));

        self::assertSame(['combined:bad'], ObserverFixture::results($this->writer));
    }

    public function testUnroutedRequestRecordsNothing(): void
    {
        $this->observer->onHttpMeasured(new HttpMeasurement('GET', null, 404, 0.01, null));

        self::assertSame([], $this->writer->writes);
    }

    public function testRouteWithoutSloRecordsNothing(): void
    {
        $this->observer->onHttpMeasured(new HttpMeasurement('GET', 'payment_list', 200, 0.01, null));
        $this->observer->onHttpMeasured(new HttpMeasurement('GET', 'unknown_route', 200, 0.01, null));

        self::assertSame([], $this->writer->writes);
    }

    public function testInvalidMeasurementIsLoggedNotThrown(): void
    {
        $this->observer->onHttpMeasured(new HttpMeasurement('POST', 'payment_create', 200, INF, null));

        self::assertSame([], $this->writer->writes);
        self::assertCount(1, $this->logger->records);
        self::assertSame('warning', $this->logger->records[0][0]);
        self::assertSame('payment_create', $this->logger->contexts[0]['route']);
    }

    public function testFailingHttpPointsFlagIsLoggedNotThrown(): void
    {
        $observer = new HttpSloObserver($this->index(), new FailingCatalog($this->catalog, ['hasHttpPoints']), $this->action(), $this->logger);

        $observer->onHttpMeasured(new HttpMeasurement('POST', 'payment_create', 200, 0.01, null));

        $this->assertLoggedOnce();
    }

    public function testFailingPointIndexIsLoggedNotThrown(): void
    {
        $index = self::createStub(RoutePointIndexInterface::class);
        $index->method('pointsFor')->willThrowException(new RuntimeException('index broken'));
        $observer = new HttpSloObserver($index, $this->catalog, $this->action(), $this->logger);

        $observer->onHttpMeasured(new HttpMeasurement('POST', 'payment_create', 200, 0.01, null));

        $this->assertLoggedOnce();
    }

    public function testFailingPointLookupInsideRecordingIsLoggedNotThrown(): void
    {
        $writer = new InMemoryEventWriter();
        $routes = new InMemoryRouteCatalog([new RouteInfo('payment_create', '/payments', ['POST'], self::CONTROLLER)]);
        $failing = new FailingCatalog($this->catalog, ['forPoint']);
        $record = new Action($failing, new MetricBindingResolver($routes, $this->catalog, new MetricsBundleSeriesCatalog()), new RevisionResolver(new RevisionCalculator(), $this->catalog), new EventClassifier(), $writer, $this->logger, new FixedPrecomputedRevisions());
        $observer = new HttpSloObserver($this->index(), $this->catalog, $record, $this->logger);

        $observer->onHttpMeasured(new HttpMeasurement('POST', 'payment_create', 200, 0.01, null));

        self::assertSame([], $writer->writes);
        self::assertSame([['warning', 'SLO event was not recorded.']], $this->logger->records);
        self::assertSame('http_route:' . self::CONTROLLER, $this->logger->contexts[0]['point']);
    }

    public function testApplicationWithoutHttpSlosNeverTouchesTheRouter(): void
    {
        $catalog = CatalogFixture::build(static function (CatalogAssembler $assembler): void {
            $assembler->declare(PointId::of(PointType::Operation, 'export'), new Slo('export', [new Availability(99)]));
        });
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::never())->method('getRouteCollection');
        $routes = new RouterRouteCatalog(new RouterRouteReader($router, $catalog), new RouteIndexFile(sys_get_temp_dir() . '/msstc4symfony-slo-no-route-index', false));
        $writer = new InMemoryEventWriter();
        $observer = new HttpSloObserver(
            new RoutePointIndex($routes, $catalog, new RouteIndexFile(sys_get_temp_dir() . '/msstc4symfony-slo-no-route-index', false), $this->logger),
            $catalog,
            ObserverFixture::action($catalog, $routes, $writer, $this->logger),
            $this->logger,
        );

        $observer->onHttpMeasured(new HttpMeasurement('GET', 'any_route', 200, 0.01, null));

        self::assertSame([], $writer->writes);
        self::assertSame([], $this->logger->records);
    }

    private function index(): RoutePointIndex
    {
        $routes = new InMemoryRouteCatalog([new RouteInfo('payment_create', '/payments', ['POST'], self::CONTROLLER)]);

        return new RoutePointIndex($routes, $this->catalog, new RouteIndexFile(sys_get_temp_dir() . '/msstc4symfony-slo-no-route-index', false), $this->logger);
    }

    private function action(): Action
    {
        return ObserverFixture::action($this->catalog, new InMemoryRouteCatalog(), $this->writer, $this->logger);
    }

    private function assertLoggedOnce(): void
    {
        self::assertSame([], $this->writer->writes);
        self::assertSame([['warning', 'SLO event was not recorded.']], $this->logger->records);
        self::assertSame('payment_create', $this->logger->contexts[0]['route']);
        self::assertInstanceOf(RuntimeException::class, $this->logger->contexts[0]['exception']);
    }
}
