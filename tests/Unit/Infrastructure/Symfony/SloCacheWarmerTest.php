<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Binding\MetricBindingResolver;
use Msstc4Symfony\SloBundle\Application\Binding\RevisionResolver;
use Msstc4Symfony\SloBundle\Application\Catalog\Validate\Action;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Service\RevisionCalculator;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Logging\FailSafeLogger;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\MetricsBundleSeriesCatalog;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\RouterRouteReader;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\SloCacheWarmer;
use Msstc4Symfony\SloBundle\Test\Integration\TestContainer;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\InMemoryRouteCatalog;
use Override;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class SloCacheWarmerTest extends TestCase
{
    private const CACHE_DIR = '/cache/prod';

    public function testIsOptionalAndTaggedAsCacheWarmerWithTheFailSafeLogger(): void
    {
        self::assertTrue($this->warmer(new InMemoryRouteCatalog(), $this->logger())->isOptional());

        $definition = TestContainer::compile()->getDefinition(SloCacheWarmer::class);
        self::assertTrue($definition->hasTag('kernel.cache_warmer'));
        $logger = $definition->getArgument(3);
        self::assertInstanceOf(Reference::class, $logger);
        self::assertSame(FailSafeLogger::class, (string) $logger);
    }

    public function testErrorsAndWarningsAreLoggedAsWarningsAndNothingIsPreloaded(): void
    {
        $logger = $this->logger();
        $warmer = $this->warmer(new InMemoryRouteCatalog(), $logger);

        self::assertSame([], $warmer->warmUp(self::CACHE_DIR));
        self::assertCount(2, $logger->records);
        self::assertSame('warning', $logger->records[0][0]);
        self::assertSame('SLO catalog error.', $logger->records[0][1]);
        $error = $logger->contexts[0]['error'];
        self::assertIsString($error);
        self::assertStringContainsString('SLO payment.create: A::create has no route', $error);
        self::assertSame('SLO catalog warning.', $logger->records[1][1]);
        self::assertSame('a warning', $logger->contexts[1]['warning']);
    }

    public function testWritesTheRouteIndexIntoTheCacheDirectory(): void
    {
        $collection = new RouteCollection();
        $collection->add('create', new Route('/create', ['_controller' => 'A::create']));
        $collection->add('other', new Route('/other', ['_controller' => 'A::other']));

        $router = self::createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);

        $store = new InMemoryRouteIndexStore();
        $this->warmer(new InMemoryRouteCatalog(), $this->logger(), $router, $store)->warmUp(self::CACHE_DIR);
        $data = $store->written[self::CACHE_DIR] ?? null;

        self::assertNotNull($data);
        self::assertSame(['http_route:A::create'], array_map(static fn (PointId $point): string => $point->toString(), $data->pointsFor('create')));
        self::assertSame([], $data->pointsFor('other'));
        self::assertCount(2, $data->all());
        self::assertSame(['payment.create|availability' => ''], $data->revisions());
    }

    public function testNeverThrowsWhenValidationFails(): void
    {
        $routes = new class implements RouteCatalogInterface {
            #[Override]
            public function routesFor(string $controller): array
            {
                throw new RuntimeException('router broken');
            }

            #[Override]
            public function all(): array
            {
                return [];
            }
        };
        $logger = $this->logger();

        self::assertSame([], $this->warmer($routes, $logger)->warmUp(self::CACHE_DIR));
        self::assertCount(1, $logger->records);
        self::assertSame('SLO catalog validation failed during cache warm-up.', $logger->records[0][1]);
        self::assertInstanceOf(RuntimeException::class, $logger->contexts[0]['exception']);
    }

    public function testNeverThrowsWhenTheRoutesCannotBeRead(): void
    {
        $router = self::createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willThrowException(new RuntimeException('router broken'));
        $logger = $this->logger();
        $store = new InMemoryRouteIndexStore();

        self::assertSame([], $this->warmer(new InMemoryRouteCatalog(), $logger, $router, $store)->warmUp(self::CACHE_DIR));
        self::assertSame('SLO route index could not be built during cache warm-up, routes are read on first use.', $logger->records[2][1]);
        self::assertInstanceOf(RuntimeException::class, $logger->contexts[2]['exception']);
        self::assertSame([], $store->written);
    }

    public function testNeverThrowsWhenTheIndexCannotBeWritten(): void
    {
        $logger = $this->logger();

        self::assertSame([], $this->warmer(new InMemoryRouteCatalog(), $logger, null, new InMemoryRouteIndexStore(failWrites: true))->warmUp(self::CACHE_DIR));
        self::assertSame('SLO route index could not be written during cache warm-up, routes are read on first use.', $logger->records[2][1]);
        self::assertInstanceOf(RuntimeException::class, $logger->contexts[2]['exception']);
    }

    public function testRoutesAreStillWrittenWhenTheRevisionsCannotBePrecomputed(): void
    {
        $collection = new RouteCollection();
        $collection->add('create', new Route('/create', ['_controller' => 'A::create']));

        $router = self::createStub(RouterInterface::class);
        $router->method('getRouteCollection')->willReturn($collection);
        $catalog = $this->catalog();
        $logger = $this->logger();
        $store = new InMemoryRouteIndexStore();
        $warmer = new SloCacheWarmer(
            new Action($catalog, $catalog, new InMemoryRouteCatalog(), new MetricBindingResolver(new InMemoryRouteCatalog(), $catalog, new MetricsBundleSeriesCatalog())),
            new RouterRouteReader($router, $catalog),
            new AllFailingCatalog($catalog),
            $logger,
            $catalog,
            new RevisionResolver(new RevisionCalculator(), $catalog),
            new MetricsBundleSeriesCatalog(),
            $store,
        );

        self::assertSame([], $warmer->warmUp(self::CACHE_DIR));
        $data = $store->written[self::CACHE_DIR] ?? null;
        self::assertNotNull($data);
        self::assertSame(['http_route:A::create'], array_map(static fn (PointId $point): string => $point->toString(), $data->pointsFor('create')));
        self::assertSame([], $data->revisions());
        self::assertContains('SLO objective revisions could not be precomputed during cache warm-up, they are resolved on first use.', array_column($logger->records, 1));
    }

    public function testNeverThrowsWhenTheLoggerFails(): void
    {
        $throwing = new class extends AbstractLogger {
            /**
             * @param array<array-key, mixed> $context
             */
            #[Override]
            public function log(mixed $level, string|Stringable $message, array $context = []): void
            {
                throw new RuntimeException('logger broken');
            }
        };

        self::assertSame([], $this->warmer(new InMemoryRouteCatalog(), new FailSafeLogger($throwing))->warmUp(self::CACHE_DIR));
    }

    private function warmer(RouteCatalogInterface $routes, LoggerInterface $logger, ?RouterInterface $router = null, ?InMemoryRouteIndexStore $store = null): SloCacheWarmer
    {
        $catalog = $this->catalog();

        return new SloCacheWarmer(
            new Action($catalog, $catalog, $routes, new MetricBindingResolver($routes, $catalog, new MetricsBundleSeriesCatalog())),
            new RouterRouteReader($router, $catalog),
            $catalog,
            $logger,
            $catalog,
            new RevisionResolver(new RevisionCalculator(), $catalog),
            new MetricsBundleSeriesCatalog(),
            $store ?? new InMemoryRouteIndexStore(),
        );
    }

    private function catalog(): CompiledSloCatalog
    {
        return CatalogFixture::build(static function ($assembler): void {
            $assembler->declare(PointId::of(PointType::HttpRoute, 'A::create'), new Slo('payment.create', [new Availability(99.9)]));
        }, new CatalogExtras(warnings: ['a warning']));
    }

    private function logger(): RecordingLogger
    {
        return new RecordingLogger();
    }
}
