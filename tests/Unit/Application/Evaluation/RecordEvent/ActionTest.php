<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Evaluation\RecordEvent;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Binding\RevisionResolver;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Action;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Input;
use Msstc4Symfony\SloBundle\Application\Port\CatalogDiagnosticsInterface;
use Msstc4Symfony\SloBundle\Application\Port\EventWriterInterface;
use Msstc4Symfony\SloBundle\Application\Port\PrecomputedRevisionsInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Enum\StrategyPreference;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Service\EventClassifier;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Observation;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Revision;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouteIndexFile;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouterRouteCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouterRouteReader;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\FailingCatalog;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\InMemoryRouteCatalog;
use Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Routing\RouterInterface;

final class ActionTest extends TestCase
{
    private const CONTROLLER = 'App\PaymentController::create';

    public function testOnlyTheDedicatedLatencyObjectiveIsWritten(): void
    {
        $writer = new InMemoryEventWriter();
        $action = $this->action($this->paymentCatalog(), $writer, new RecordingLogger());

        $action->record(new Input($this->point(), Observation::http(200, 0.1)));

        self::assertCount(1, $writer->writes);
        self::assertSame(['payment.create', 'latency'], \array_slice($writer->writes[0], 0, 2));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $writer->writes[0][2]);
        self::assertSame('good', $writer->writes[0][3]);
    }

    public function testRevisionMatchesTheCalculator(): void
    {
        $catalog = $this->paymentCatalog();
        $writer = new InMemoryEventWriter();
        $this->action($catalog, $writer, new RecordingLogger())->record(new Input($this->point(), Observation::http(200, 0.1)));

        $definition = $catalog->all()[0];

        self::assertSame((new RevisionCalculator())->revision($definition, ObjectiveKind::Latency, [])->value, $writer->writes[0][2]);
    }

    public function testSlowEventIsBad(): void
    {
        $writer = new InMemoryEventWriter();
        $this->action($this->paymentCatalog(), $writer, new RecordingLogger())->record(new Input($this->point(), Observation::http(200, 0.3)));

        self::assertSame('bad', $writer->writes[0][3]);
    }

    public function testLatencyIneligibleEventWritesNothing(): void
    {
        $writer = new InMemoryEventWriter();
        $this->action($this->paymentCatalog(), $writer, new RecordingLogger())->record(new Input($this->point(), Observation::http(500, 0.1)));

        self::assertSame([], $writer->writes);
    }

    public function testPointWithoutSlosWritesNothing(): void
    {
        $writer = new InMemoryEventWriter();
        $this->action($this->paymentCatalog(), $writer, new RecordingLogger())->record(new Input(PointId::of(PointType::HttpRoute, 'App\Other::x'), Observation::http(200, 0.1)));

        self::assertSame([], $writer->writes);
    }

    public function testWriterFailureIsLoggedNotThrown(): void
    {
        $logger = new RecordingLogger();
        $this->action($this->paymentCatalog(), new ThrowingEventWriter(), $logger)->record(new Input($this->point(), Observation::http(200, 0.1)));

        self::assertCount(1, $logger->records);
        self::assertSame('latency', $logger->contexts[0]['objective']);
        self::assertSame('warning', $logger->records[0][0]);
        self::assertSame('payment.create', $logger->contexts[0]['slo']);
        self::assertSame('http_route:' . self::CONTROLLER, $logger->contexts[0]['point']);
        self::assertInstanceOf(RuntimeException::class, $logger->contexts[0]['exception']);
        self::assertSame('storage down', $logger->contexts[0]['exception']->getMessage());
    }

    public function testConflictedNameWarnsOncePerNameAndNeverWrites(): void
    {
        $catalog = CatalogFixture::build(function (CatalogAssembler $assembler): void {
            $assembler->declare(PointId::of(PointType::HttpRoute, 'A::one'), new Slo('dup.slo', [new Availability(99.9)], strategy: StrategyPreference::Dedicated));
            $assembler->declare(PointId::of(PointType::HttpRoute, 'A::two'), new Slo('dup.slo', [new Availability(99.5)], strategy: StrategyPreference::Dedicated));
        });
        $writer = new InMemoryEventWriter();
        $logger = new RecordingLogger();
        $action = $this->action($catalog, $writer, $logger);
        $input = new Input(PointId::of(PointType::HttpRoute, 'A::one'), Observation::http(200, 0.1));

        $action->record($input);
        $action->record($input);

        self::assertSame([], $writer->writes);
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0][0]);
        self::assertSame('dup.slo', $logger->contexts[0]['slo']);

        $action->reset();
        $action->record($input);

        self::assertCount(2, $logger->records);
    }

    public function testResetKeepsSuccessfulResolutions(): void
    {
        $routes = $this->createMock(RouteCatalogInterface::class);
        $routes->expects(self::exactly(2))->method('routesFor')->willReturn([new RouteInfo('payment_create', '/payments', [], self::CONTROLLER)]);
        $writer = new InMemoryEventWriter();
        $action = $this->action($this->paymentCatalog(), $writer, new RecordingLogger(), $routes);
        $input = new Input($this->point(), Observation::http(200, 0.1));

        for ($i = 0; $i < 3; $i++) {
            $action->record($input);
            $action->reset();
        }

        self::assertCount(3, $writer->writes);
        self::assertSame($writer->writes[0], $writer->writes[2]);
    }

    public function testPrecomputedRevisionsSkipTheRuntimeResolution(): void
    {
        $routes = $this->createMock(RouteCatalogInterface::class);
        $routes->expects(self::never())->method('routesFor');
        $writer = new InMemoryEventWriter();
        $precomputed = new FixedPrecomputedRevisions(['payment.create|availability' => false, 'payment.create|latency' => Revision::fromHex('0badcafe')]);
        $action = $this->action($this->paymentCatalog(), $writer, new RecordingLogger(), $routes, $precomputed);

        $action->record(new Input($this->point(), Observation::http(200, 0.1)));
        $action->record(new Input($this->point(), Observation::http(200, 0.1)));

        self::assertSame([['payment.create', 'latency', '0badcafe', 'good'], ['payment.create', 'latency', '0badcafe', 'good']], $writer->writes);
        self::assertSame(2, $precomputed->lookups);
    }

    public function testFailingPointLookupIsLoggedNotThrown(): void
    {
        $writer = new InMemoryEventWriter();
        $logger = new RecordingLogger();
        $this->action(new FailingCatalog($this->paymentCatalog(), ['forPoint']), $writer, $logger)->record(new Input($this->point(), Observation::http(200, 0.1)));

        self::assertSame([], $writer->writes);
        self::assertSame([['warning', 'SLO event was not recorded.']], $logger->records);
        self::assertSame('http_route:' . self::CONTROLLER, $logger->contexts[0]['point']);
    }

    public function testRepeatedWriteFailuresAreLoggedOncePerObjectiveAndExceptionClassUntilReset(): void
    {
        $logger = new RecordingLogger();
        $action = $this->action($this->paymentCatalog(), new ThrowingEventWriter(), $logger);
        $input = new Input($this->point(), Observation::http(200, 0.1));

        for ($i = 0; $i < 5; $i++) {
            $action->record($input);
        }
        self::assertCount(1, $logger->records);

        $action->reset();
        $action->record($input);
        self::assertCount(2, $logger->records);
    }

    public function testWriteFailureWarningsAreBounded(): void
    {
        $catalog = CatalogFixture::build(function (CatalogAssembler $assembler): void {
            for ($i = 0; $i < 101; $i++) {
                $assembler->declare($this->point(), new Slo(\sprintf('payment.s%03d', $i), [new Availability(99.9)], strategy: StrategyPreference::Dedicated));
            }
        });
        $logger = new RecordingLogger();
        $action = $this->action($catalog, new ThrowingEventWriter(), $logger);

        $action->record(new Input($this->point(), Observation::http(200, 0.1)));
        $action->record(new Input($this->point(), Observation::http(200, 0.1)));

        self::assertCount(101, $logger->records);
        self::assertSame('Further SLO recording failures are suppressed until reset.', $logger->records[100][1]);
    }

    public function testBindingIsResolvedOncePerObjectiveOverManyEvents(): void
    {
        $routes = $this->createMock(RouteCatalogInterface::class);
        $routes->expects(self::exactly(2))->method('routesFor')->willReturn([new RouteInfo('payment_create', '/payments', [], self::CONTROLLER)]);
        $catalog = $this->paymentCatalog();
        $writer = new InMemoryEventWriter();
        $action = $this->action($catalog, $writer, new RecordingLogger(), $routes);

        for ($i = 0; $i < 5; $i++) {
            $action->record(new Input($this->point(), Observation::http(200, 0.1)));
        }

        self::assertCount(5, $writer->writes);
    }

    public function testThrowingRouterIsMemoisedAsSkipAndLoggedOncePerObjective(): void
    {
        $router = $this->createMock(RouterInterface::class);
        $router->expects(self::exactly(2))->method('getRouteCollection')->willThrowException(new RuntimeException('router broken'));
        $catalog = $this->paymentCatalog();
        $writer = new InMemoryEventWriter();
        $logger = new RecordingLogger();
        $action = $this->action($catalog, $writer, $logger, new RouterRouteCatalog(new RouterRouteReader($router, $catalog), new RouteIndexFile(sys_get_temp_dir() . '/msstc4symfony-slo-no-route-index', false)));

        for ($i = 0; $i < 5; $i++) {
            $action->record(new Input($this->point(), Observation::http(200, 0.1)));
        }

        self::assertSame([], $writer->writes);
        self::assertCount(2, $logger->records);
        self::assertSame(['availability', 'latency'], array_map(static fn (array $context): mixed => $context['objective'] ?? null, $logger->contexts));
        self::assertSame('payment.create', $logger->contexts[0]['slo']);
        self::assertInstanceOf(RuntimeException::class, $logger->contexts[0]['exception']);
    }

    public function testFailedResolutionIsRetriedAfterReset(): void
    {
        $routes = $this->createMock(RouteCatalogInterface::class);
        $routes->expects(self::exactly(4))->method('routesFor')->willReturnOnConsecutiveCalls(
            self::throwException(new RuntimeException('router broken')),
            self::throwException(new RuntimeException('router broken')),
            [new RouteInfo('payment_create', '/payments', [], self::CONTROLLER)],
            [new RouteInfo('payment_create', '/payments', [], self::CONTROLLER)],
        );
        $writer = new InMemoryEventWriter();
        $action = $this->action($this->paymentCatalog(), $writer, new RecordingLogger(), $routes);
        $input = new Input($this->point(), Observation::http(200, 0.1));

        $action->record($input);
        $action->reset();
        $action->record($input);
        $action->reset();
        $action->record($input);

        self::assertCount(2, $writer->writes);
    }

    public function testFailingConflictLookupDoesNotStopRecording(): void
    {
        $catalog = new FailingCatalog($this->paymentCatalog(), ['conflictedNamesFor']);
        $writer = new InMemoryEventWriter();
        $logger = new RecordingLogger();
        $action = new Action($catalog, new MetricBindingResolver(new InMemoryRouteCatalog([new RouteInfo('payment_create', '/payments', [], self::CONTROLLER)]), $catalog->inner), new RevisionResolver(new RevisionCalculator(), $catalog->inner), new EventClassifier(), $writer, $logger, new FixedPrecomputedRevisions());

        $action->record(new Input($this->point(), Observation::http(200, 0.1)));

        self::assertCount(1, $writer->writes);
        self::assertCount(1, $logger->records);
        self::assertSame('http_route:' . self::CONTROLLER, $logger->contexts[0]['point']);
    }

    private function point(): PointId
    {
        return PointId::of(PointType::HttpRoute, self::CONTROLLER);
    }

    private function paymentCatalog(): CompiledSloCatalog
    {
        return CatalogFixture::build(function (CatalogAssembler $assembler): void {
            $assembler->declare($this->point(), new Slo('payment.create', [new Availability(99.9), new Latency('200ms', 99)]));
        });
    }

    private function action(CatalogDiagnosticsInterface&SloCatalogInterface $catalog, EventWriterInterface $writer, LoggerInterface $logger, ?RouteCatalogInterface $routes = null, ?PrecomputedRevisionsInterface $precomputed = null): Action
    {
        $routes ??= new InMemoryRouteCatalog([new RouteInfo('payment_create', '/payments', [], self::CONTROLLER)]);

        return new Action($catalog, new MetricBindingResolver($routes, $catalog), new RevisionResolver(new RevisionCalculator(), $catalog), new EventClassifier(), $writer, $logger, $precomputed ?? new FixedPrecomputedRevisions());
    }
}
