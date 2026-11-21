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
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogContents;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Discovery\DefinitionFactory;
use Msstc4Symfony\SloBundle\Application\Discovery\DiscoveryErrors;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Action;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Input;
use Msstc4Symfony\SloBundle\Application\Port\RouteInfo;
use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Service\EventClassifier;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Observation;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CatalogDump;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsBundleSeriesCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsEventWriter;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\RouteIndexFile;
use Msstc4Symfony\SloBundle\Infrastructure\RouteIndex\WarmedRevisions;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RoutePointIndex;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouterRouteCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouterRouteReader;
use Msstc4Symfony\SloBundle\Test\Integration\Hardening\HardeningKernel;
use Msstc4Symfony\SloBundle\Test\Integration\NullMetricRecorder;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\InMemoryRouteCatalog;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Evaluation\RecordEvent\FixedPrecomputedRevisions;
use Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\RouteIndex\RouteIndexFixture;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @phpstan-import-type CatalogShape from CatalogDump
 *
 * Overhead of classifier + writer per event (plan ruling 11). No threshold: the numbers go to the README, `make benchmark`.
 */
#[Group('benchmark')]
final class RecordEventBenchmarkTest extends TestCase
{
    private const FINGERPRINT = 'catalog-a';

    private const CONTROLLER = 'App\PaymentController::create';

    private const WARMUP = 2_000;

    private const EVENTS = 100_000;

    private const ROUNDS = 5;

    private const COLD_SLOS = 500;

    private const COLD_REQUESTS = 2_000;

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

    /**
     * A PHP-FPM request after deploy: every service is new, the route index comes from the warmed file (included once,
     * no opcache in the CLI unless enabled), then one HTTP event for the request's route.
     */
    public function testColdRequestOverAWarmedIndexOfFiveHundredSlos(): void
    {
        $dir = sys_get_temp_dir() . '/msstc4symfony-slo-benchmark-' . getmypid();
        (new Filesystem())->remove($dir);
        $routes = [];
        $dump = CatalogDump::toArray((static function () use (&$routes): CatalogContents {
            $assembler = new CatalogAssembler();
            for ($i = 0; $i < self::COLD_SLOS; $i++) {
                $controller = \sprintf('App\Controller%d::action', $i);
                $routes[] = new RouteInfo('route_' . $i, '/r/' . $i, ['GET'], $controller);
                $assembler->declare(PointId::of(PointType::HttpRoute, $controller), new Slo('slo.' . $i, [new Availability(99.9), new Latency('200ms', 99)]));
            }

            return $assembler->assemble(new DefinitionFactory('30d', ['5xx'], [], true, false), new DiscoveryErrors(), new CatalogExtras(hasHttpPoints: true));
        })(), new MetricsBundleSeriesCatalog());
        $warm = new CompiledSloCatalog($dump);
        $built = RouteIndexFixture::builder($warm, $warm)->build($routes);
        (new RouteIndexFile($dir, false, self::FINGERPRINT))->write($dir, $built);
        // opcache.file_update_protection skips files modified in the last seconds: age the file like a deployed one.
        touch($dir . '/' . RouteIndexFile::PATH, time() - 60);
        $observation = Observation::http(200, 0.05);
        $recorder = new NullMetricRecorder();

        try {
            self::assertEquals($built, (new RouteIndexFile($dir, false, self::FINGERPRINT))->load(), 'the requests read the warmed index');
            $best = INF;
            for ($round = 0; $round < self::ROUNDS; $round++) {
                $start = hrtime(true);
                for ($request = 0; $request < self::COLD_REQUESTS; $request++) {
                    $this->coldRequest($dump, $dir, 'route_' . ($request % self::COLD_SLOS), $observation, $recorder);
                }
                $best = min($best, (hrtime(true) - $start) / 1_000 / self::COLD_REQUESTS);
            }
        } finally {
            (new Filesystem())->remove($dir);
        }

        fwrite(\STDERR, \sprintf(
            "\n[benchmark] cold request (%d SLOs x 2 objectives, warmed route index, opcache %s): %.2f us/request, best of %d rounds x %d requests, PHP %s, %s UTC\n",
            self::COLD_SLOS,
            \function_exists('opcache_get_status') && \is_array(@opcache_get_status(false)) ? 'on' : 'off',
            $best,
            self::ROUNDS,
            self::COLD_REQUESTS,
            \PHP_VERSION,
            gmdate('Y-m-d H:i'),
        ));
        self::assertSame(self::ROUNDS * self::COLD_REQUESTS, $recorder->increments, 'every request records its dedicated latency objective (availability reads the existing series)');
    }

    /**
     * @param CatalogShape $dump
     * @param non-empty-string $route
     */
    private function coldRequest(array $dump, string $dir, string $route, Observation $observation, NullMetricRecorder $recorder): void
    {
        $catalog = new CompiledSloCatalog($dump);
        $file = new RouteIndexFile($dir, false, self::FINGERPRINT);
        $routes = new RouterRouteCatalog(new RouterRouteReader(null, $catalog), $file);
        $index = new RoutePointIndex($routes, $catalog, $file, new NullLogger(), RouteIndexFixture::builder($catalog, $catalog));
        $action = new Action(
            $catalog,
            new MetricBindingResolver($routes, $catalog, new MetricsBundleSeriesCatalog()),
            new RevisionResolver(new RevisionCalculator(), $catalog),
            new EventClassifier(),
            new MetricsEventWriter($recorder),
            new NullLogger(),
            new WarmedRevisions($file),
        );
        foreach ($index->pointsFor($route) as $point) {
            $action->record(new Input($point, $observation));
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
            new MetricBindingResolver(new InMemoryRouteCatalog([new RouteInfo('payment_create', '/payments', [], self::CONTROLLER)]), $catalog, new MetricsBundleSeriesCatalog()),
            new RevisionResolver(new RevisionCalculator(), $catalog),
            new EventClassifier(),
            new MetricsEventWriter($recorder),
            new NullLogger(),
            $warmed ? $this->warmedRevisions($catalog) : new FixedPrecomputedRevisions(),
        );
        $good = new Input($point, Observation::http(200, 0.05));
        $bad = new Input($point, Observation::http(500, 0.05));

        for ($i = 0; $i < self::WARMUP; $i++) {
            $action->record($good);
        }

        $best = INF;
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $start = hrtime(true);
            for ($i = 0; $i < self::EVENTS; $i++) {
                $action->record($i % 10 === 0 ? $bad : $good);
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
