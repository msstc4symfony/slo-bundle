<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration;

use LogicException;
use Msstc4Symfony\SloBundle\SloBundle;
use Msstc4Symfony\SloBundle\Test\Integration\Kernel\TestKernel;
use Override;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class SloBundleTest extends TestCase
{
    #[Override]
    protected function setUp(): void
    {
        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    #[Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove(TestKernel::cacheRoot());
    }

    public function testBundleBootsWithMetricsBundle(): void
    {
        $kernel = new TestKernel('test', false);
        $kernel->boot();

        try {
            self::assertInstanceOf(SloBundle::class, $kernel->getBundle('SloBundle'));
            self::assertTrue($kernel->getContainer()->has('kernel'));
        } finally {
            $kernel->shutdown();
        }
    }

    public function testBundleCompilesWithoutFrameworkServices(): void
    {
        $container = TestContainer::compile();

        self::assertTrue($container->isCompiled());
    }

    public function testCompilationWithoutMetricsBundleFailsWithAHint(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SloBundle needs MetricsBundle: register Msstc4Symfony\\MetricsBundle\\MetricsBundle in config/bundles.php.');

        TestContainer::compile(withMetricsBundle: false);
    }

    public function testMetricsListShowsTheSloCounter(): void
    {
        $kernel = new TestKernel('test', false);
        $kernel->boot();

        try {
            $tester = new CommandTester((new Application($kernel))->find('metrics:list'));
            $tester->execute([]);

            self::assertStringContainsString('slo_events_total', $tester->getDisplay());
        } finally {
            $kernel->shutdown();
        }
    }
}
