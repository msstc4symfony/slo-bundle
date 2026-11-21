<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration;

use Msstc4Symfony\SloBundle\SloBundle;
use Msstc4Symfony\SloBundle\Test\Integration\Kernel\TestKernel;
use Override;
use PHPUnit\Framework\TestCase;
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
}
