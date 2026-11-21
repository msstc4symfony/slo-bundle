<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\Symfony;

use Msstc4Symfony\SloBundle\Application\Attribute\Availability;
use Msstc4Symfony\SloBundle\Application\Attribute\Slo;
use Msstc4Symfony\SloBundle\Application\Catalog\Validate\Action;
use Msstc4Symfony\SloBundle\Application\Discovery\CatalogExtras;
use Msstc4Symfony\SloBundle\Application\Port\RouteCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Infrastructure\Symfony\SloCacheWarmer;
use Msstc4Symfony\SloBundle\Test\Integration\TestContainer;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\CatalogFixture;
use Msstc4Symfony\SloBundle\Test\Unit\Application\Catalog\InMemoryRouteCatalog;
use Override;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SloCacheWarmerTest extends TestCase
{
    public function testIsOptionalAndTaggedAsCacheWarmerOnTheSloChannel(): void
    {
        self::assertTrue((new SloCacheWarmer($this->action(new InMemoryRouteCatalog()), $this->logger()))->isOptional());

        $definition = TestContainer::compile()->getDefinition(SloCacheWarmer::class);
        self::assertTrue($definition->hasTag('kernel.cache_warmer'));
        self::assertSame([['channel' => 'slo_bundle']], $definition->getTag('monolog.logger'));
    }

    public function testErrorsAndWarningsAreLoggedAsWarningsAndNothingIsPreloaded(): void
    {
        $logger = $this->logger();
        $warmer = new SloCacheWarmer($this->action(new InMemoryRouteCatalog()), $logger);

        self::assertSame([], $warmer->warmUp(sys_get_temp_dir()));
        self::assertCount(2, $logger->records);
        self::assertSame('warning', $logger->records[0][0]);
        self::assertStringContainsString('SLO catalog error: SLO payment.create: A::create has no route', $logger->records[0][1]);
        self::assertStringContainsString('SLO catalog warning: a warning', $logger->records[1][1]);
    }

    public function testNeverThrows(): void
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

        self::assertSame([], (new SloCacheWarmer($this->action($routes), $logger))->warmUp(sys_get_temp_dir()));
        self::assertCount(1, $logger->records);
        self::assertSame('SLO catalog validation failed during cache warm-up.', $logger->records[0][1]);
    }

    private function action(RouteCatalogInterface $routes): Action
    {
        $catalog = CatalogFixture::build(static function ($assembler): void {
            $assembler->declare(PointId::of(PointType::HttpRoute, 'A::create'), new Slo('payment.create', [new Availability(99.9)]));
        }, new CatalogExtras(warnings: ['a warning']));

        return new Action($catalog, $catalog, $routes);
    }

    private function logger(): RecordingLogger
    {
        return new RecordingLogger();
    }
}
