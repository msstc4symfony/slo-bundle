<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Evaluation\RecordEvent;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Action;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Input;
use Msstc4Symfony\SloBundle\Application\Port\EventWriterInterface;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Service\EventClassifier;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Observation;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\InMemoryRouteCatalog;
use Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

final class ActionTest extends TestCase
{
    private const CONTROLLER = 'App\PaymentController::create';

    public function testOnlyTheDedicatedLatencyObjectiveIsWritten(): void
    {
        $writer = new InMemoryEventWriter();
        $action = $this->action($this->paymentCatalog(), $writer, new RecordingLogger());

        $action(new Input($this->point(), Observation::http(200, 0.1)));

        self::assertCount(1, $writer->writes);
        self::assertSame(['payment.create', 'latency'], \array_slice($writer->writes[0], 0, 2));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', $writer->writes[0][2]);
        self::assertSame('good', $writer->writes[0][3]);
    }

    public function testRevisionMatchesTheCalculator(): void
    {
        $catalog = $this->paymentCatalog();
        $writer = new InMemoryEventWriter();
        $this->action($catalog, $writer, new RecordingLogger())(new Input($this->point(), Observation::http(200, 0.1)));

        $definition = $catalog->all()[0];

        self::assertSame((new RevisionCalculator())->revision($definition, ObjectiveKind::Latency)->value, $writer->writes[0][2]);
    }

    public function testSlowEventIsBad(): void
    {
        $writer = new InMemoryEventWriter();
        $this->action($this->paymentCatalog(), $writer, new RecordingLogger())(new Input($this->point(), Observation::http(200, 0.3)));

        self::assertSame('bad', $writer->writes[0][3]);
    }

    public function testLatencyIneligibleEventWritesNothing(): void
    {
        $writer = new InMemoryEventWriter();
        $this->action($this->paymentCatalog(), $writer, new RecordingLogger())(new Input($this->point(), Observation::http(500, 0.1)));

        self::assertSame([], $writer->writes);
    }

    public function testPointWithoutSlosWritesNothing(): void
    {
        $writer = new InMemoryEventWriter();
        $this->action($this->paymentCatalog(), $writer, new RecordingLogger())(new Input(PointId::of(PointType::HttpRoute, 'App\Other::x'), Observation::http(200, 0.1)));

        self::assertSame([], $writer->writes);
    }

    public function testWriterFailureIsLoggedNotThrown(): void
    {
        $logger = new RecordingLogger();
        $this->action($this->paymentCatalog(), new ThrowingEventWriter(), $logger)(new Input($this->point(), Observation::http(200, 0.1)));

        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0][0]);
        self::assertSame('payment.create', $logger->contexts[0]['slo']);
        self::assertSame('http_route:' . self::CONTROLLER, $logger->contexts[0]['point']);
        self::assertInstanceOf(RuntimeException::class, $logger->contexts[0]['exception']);
        self::assertSame('storage down', $logger->contexts[0]['exception']->getMessage());
    }

    public function testConflictedNameWarnsOncePerNameAndNeverWrites(): void
    {
        $catalog = CatalogFixture::build(function (CatalogAssembler $assembler): void {
            $assembler->declare(PointId::of(PointType::HttpRoute, 'A::one'), new Slo('dup.slo', [new Availability(99.9)], strategy: 'dedicated'));
            $assembler->declare(PointId::of(PointType::HttpRoute, 'A::two'), new Slo('dup.slo', [new Availability(99.5)], strategy: 'dedicated'));
        });
        $writer = new InMemoryEventWriter();
        $logger = new RecordingLogger();
        $action = $this->action($catalog, $writer, $logger);
        $input = new Input(PointId::of(PointType::HttpRoute, 'A::one'), Observation::http(200, 0.1));

        $action($input);
        $action($input);

        self::assertSame([], $writer->writes);
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0][0]);
        self::assertSame('dup.slo', $logger->contexts[0]['slo']);

        $action->reset();
        $action($input);

        self::assertCount(2, $logger->records);
    }

    public function testResetClearsTheMemoisedResolution(): void
    {
        $writer = new InMemoryEventWriter();
        $action = $this->action($this->paymentCatalog(), $writer, new RecordingLogger());
        $input = new Input($this->point(), Observation::http(200, 0.1));

        $action($input);
        $action->reset();
        $action($input);

        self::assertCount(2, $writer->writes);
        self::assertSame($writer->writes[0], $writer->writes[1]);
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

    private function action(CompiledSloCatalog $catalog, EventWriterInterface $writer, LoggerInterface $logger): Action
    {
        $routes = new InMemoryRouteCatalog([new RouteInfo('payment_create', '/payments', [], self::CONTROLLER)]);

        return new Action($catalog, new MetricBindingResolver($routes, $catalog), new RevisionCalculator(), new EventClassifier(), $writer, $logger);
    }
}
