<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Benchmark;

use Msstc4Symfony\MetricsBundle\Application\Port\MetricRecorderInterface;
use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Latency;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Binding\RevisionResolver;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogAssembler;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Action;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Input;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Service\EventClassifier;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Observation;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsEventWriter;
use Msstc4Symfony\SloBundle\Test\Integration\Hardening\HardeningKernel;
use Msstc4Symfony\SloBundle\Test\Integration\NullMetricRecorder;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\InMemoryRouteCatalog;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Evaluation\RecordEvent\FixedPrecomputedRevisions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Overhead of classifier + writer per event (plan ruling 11). No threshold: the numbers go to the README, `make benchmark`.
 */
#[Group('benchmark')]
final class RecordEventBenchmarkTest extends TestCase
{
    private const CONTROLLER = 'App\PaymentController::create';

    private const WARMUP = 2_000;

    private const EVENTS = 100_000;

    private const ROUNDS = 5;

    public function testClassifierAndWriterCostPerEventWithANullRecorder(): void
    {
        $this->measure('null recorder', new NullMetricRecorder());
    }

    public function testCostPerEventWithAKernelResetAfterEveryEvent(): void
    {
        $this->measure('null recorder, kernel.reset after every event', new NullMetricRecorder(), true);
    }

    public function testCostPerEventWithAKernelResetAfterEveryEventAndWarmedRevisions(): void
    {
        $this->measure('null recorder, kernel.reset after every event, warmed revisions', new NullMetricRecorder(), true, true);
    }

    public function testClassifierAndWriterCostPerEventWithTheInMemoryMetricsStorage(): void
    {
        (new Filesystem())->remove(HardeningKernel::cacheRoot());
        $kernel = new HardeningKernel('test', false);
        $kernel->boot();

        try {
            $container = $kernel->getContainer()->get('test.service_container');
            self::assertInstanceOf(ContainerInterface::class, $container);
            $recorder = $container->get(MetricRecorderInterface::class);
            self::assertInstanceOf(MetricRecorderInterface::class, $recorder);
            $this->measure('metrics-bundle in-memory storage', $recorder);
        } finally {
            $kernel->shutdown();
            (new Filesystem())->remove(HardeningKernel::cacheRoot());
        }
    }

    private function measure(string $label, MetricRecorderInterface $recorder, bool $resetEachEvent = false, bool $warmed = false): void
    {
        $point = PointId::of(PointType::HttpRoute, self::CONTROLLER);
        $catalog = CatalogFixture::build(static function (CatalogAssembler $assembler) use ($point): void {
            $assembler->declare($point, new Slo('payment.create', [new Availability(99.9), new Latency('200ms', 99)]));
        });
        $action = new Action(
            $catalog,
            new MetricBindingResolver(new InMemoryRouteCatalog([new RouteInfo('payment_create', '/payments', [], self::CONTROLLER)]), $catalog),
            new RevisionResolver(new RevisionCalculator(), $catalog),
            new EventClassifier(),
            new MetricsEventWriter($recorder),
            new NullLogger(),
            $warmed ? $this->warmedRevisions($catalog) : new FixedPrecomputedRevisions(),
        );
        $good = new Input($point, Observation::http(200, 0.05));
        $bad = new Input($point, Observation::http(500, 0.05));

        for ($i = 0; $i < self::WARMUP; $i++) {
            $action($good);
        }

        $best = INF;
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $start = hrtime(true);
            for ($i = 0; $i < self::EVENTS; $i++) {
                $action($i % 10 === 0 ? $bad : $good);
                if ($resetEachEvent) {
                    $action->reset();
                }
            }
            $best = min($best, (hrtime(true) - $start) / 1_000 / self::EVENTS);
        }

        fwrite(\STDERR, \sprintf(
            "\n[benchmark] RecordEvent (2 objectives, %s): %.2f us/event, best of %d rounds x %d events, PHP %s, %s UTC\n",
            $label,
            $best,
            self::ROUNDS,
            self::EVENTS,
            \PHP_VERSION,
            gmdate('Y-m-d H:i'),
        ));
        self::assertGreaterThan(0.0, $best);
    }

    private function warmedRevisions(CompiledSloCatalog $catalog): FixedPrecomputedRevisions
    {
        $definition = $catalog->all()[0];
        $revisions = new RevisionResolver(new RevisionCalculator(), $catalog);

        return new FixedPrecomputedRevisions([
            'payment.create|availability' => false,
            'payment.create|latency' => $revisions->revision($definition, ObjectiveKind::Latency),
        ]);
    }
}
