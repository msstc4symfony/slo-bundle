<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Kernel;

use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageInterface;
use Override;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\TerminableInterface;

/**
 * Durations come from metrics-bundle only; the fixture threshold is 10s so no case depends on wall time, the boundary
 * cases are covered by HttpSloObserverTest.
 */
final class HttpSloKernelTest extends KernelTestCase
{
    #[Override]
    protected function setUp(): void
    {
        (new Filesystem())->remove(HttpSloTestKernel::cacheRoot());
        self::bootKernel();
        $this->serve('/_/metrics');
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->remove(HttpSloTestKernel::cacheRoot());
    }

    public function testResponsesAreClassifiedPerObjective(): void
    {
        self::assertSame(200, $this->serve('/checkout/ok')->getStatusCode());
        self::assertSame(400, $this->serve('/checkout/bad-request')->getStatusCode());
        self::assertSame(404, $this->serve('/checkout/not-found')->getStatusCode());
        self::assertSame(302, $this->serve('/checkout/redirect')->getStatusCode());

        self::assertSame([
            'checkout.page|combined|good' => 4,
            'checkout.page|latency|good' => 4,
        ], $this->counts());
    }

    public function testUncaughtExceptionIsCountedBadExactlyOnce(): void
    {
        self::assertSame(500, $this->serve('/checkout/throw')->getStatusCode());

        self::assertSame(['checkout.page|combined|bad' => 1], $this->counts());
    }

    public function testOptionsAndExcludedRoutesRecordNothing(): void
    {
        $this->serve('/checkout/ok', Request::METHOD_OPTIONS);
        $this->serve('/_/metrics');
        $this->serve('/missing');

        self::assertSame([], $this->counts());

        $this->serve('/checkout/ok');

        self::assertSame(['checkout.page|combined|good' => 1, 'checkout.page|latency|good' => 1], $this->counts());
    }

    public function testFragmentSubRequestPageIsOneEvent(): void
    {
        self::assertSame('page:ok', $this->serve('/checkout-fragment')->getContent());

        self::assertSame(['checkout.fragment|combined|good' => 1], $this->counts());
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return HttpSloTestKernel::class;
    }

    private function serve(string $uri, string $method = Request::METHOD_GET): Response
    {
        $kernel = self::$kernel;
        self::assertInstanceOf(TerminableInterface::class, $kernel);
        $request = Request::create($uri, $method);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $storage = self::getContainer()->get(MetricStorageInterface::class);
        self::assertInstanceOf(MetricStorageInterface::class, $storage);

        return SloEventsExport::counts($storage->export()->body);
    }
}
