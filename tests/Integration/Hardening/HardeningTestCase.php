<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Hardening;

use Monolog\Handler\TestHandler;
use Msstc4Symfony\MetricsBundle\Application\Port\MetricStorageInterface;
use Msstc4Symfony\SloBundle\Test\Integration\Kernel\SloEventsExport;
use Override;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\TerminableInterface;
use Symfony\Contracts\Service\ResetInterface;

abstract class HardeningTestCase extends KernelTestCase
{
    #[Override]
    protected function setUp(): void
    {
        (new Filesystem())->remove(HardeningKernel::cacheRoot());
    }

    #[Override]
    protected function tearDown(): void
    {
        parent::tearDown();
        (new Filesystem())->remove(HardeningKernel::cacheRoot());
    }

    #[Override]
    protected static function getKernelClass(): string
    {
        return HardeningKernel::class;
    }

    protected function boot(string $environment = 'test'): void
    {
        self::bootKernel(['environment' => $environment]);
        $this->serve('/_/metrics');
        // Kernel::boot() resets services once after a request; registering the console commands triggers it here, not between dispatch and consume.
        $this->application()->find('list');
    }

    protected function serve(string $uri): Response
    {
        $kernel = self::$kernel;
        self::assertInstanceOf(TerminableInterface::class, $kernel);
        $request = Request::create($uri);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response;
    }

    protected function resetServices(): void
    {
        $resetter = self::getContainer()->get('services_resetter');
        self::assertInstanceOf(ResetInterface::class, $resetter);
        $resetter->reset();
    }

    protected function export(): string
    {
        $storage = self::getContainer()->get(MetricStorageInterface::class);
        self::assertInstanceOf(MetricStorageInterface::class, $storage);

        return $storage->export()->body;
    }

    /**
     * @return array<string, int>
     */
    protected function counts(): array
    {
        return SloEventsExport::counts($this->export());
    }

    protected function logHandler(): TestHandler
    {
        $handler = self::getContainer()->get(TestHandler::class);
        self::assertInstanceOf(TestHandler::class, $handler);

        return $handler;
    }

    protected function application(): Application
    {
        self::assertNotNull(self::$kernel);

        return new Application(self::$kernel);
    }

    protected function consume(string $transport): CommandTester
    {
        $consume = new CommandTester($this->application()->find('messenger:consume'));
        $consume->execute(['receivers' => [$transport], '--limit' => 1, '--time-limit' => 10, '--no-reset' => null]);

        return $consume;
    }
}
