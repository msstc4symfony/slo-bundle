<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\DependencyInjection;

use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Msstc4Symfony\SloBundle\Infrastructure\Catalog\CompiledSloCatalog;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\InvokableController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Message\ProcessPayment;
use Msstc4Symfony\SloBundle\Test\Fixtures\Message\SendReceipt;
use Msstc4Symfony\SloBundle\Test\Integration\Kernel\MessengerTestKernel;
use Override;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class FrameworkDiscoveryTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        (new Filesystem())->remove(MessengerTestKernel::cacheRoot());
    }

    #[Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove(MessengerTestKernel::cacheRoot());
    }

    public function testDumpedCatalogReflectsFrameworkMessengerAndAutoconfiguration(): void
    {
        $kernel = new MessengerTestKernel('test', false);
        $kernel->boot();

        try {
            $catalog = $kernel->getContainer()->get(CompiledSloCatalog::class);
            self::assertInstanceOf(CompiledSloCatalog::class, $catalog);

            self::assertNotNull($catalog->get(SloName::fromString('payment.process')));
            self::assertNotNull($catalog->get(SloName::fromString('refund.create')));
            self::assertArrayHasKey(InvokableController::class, $catalog->extras()->controllers);
            self::assertSame(['dead', 'failed'], $catalog->extras()->failureTransports);

            $async = [];
            foreach ($catalog->extras()->handlers as $handler) {
                $async[$handler->messageClass] = $handler->async;
            }
            self::assertTrue($async[ProcessPayment::class] ?? null);
            self::assertFalse($async[SendReceipt::class] ?? null);
            self::assertArrayHasKey('request_duration_histogram_seconds', $catalog->extras()->metricBuckets);
        } finally {
            $kernel->shutdown();
        }
    }
}
