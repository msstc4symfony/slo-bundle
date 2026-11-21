<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Presentation\Command;

use Msstc4Symfony\SloBundle\Presentation\Command\GenerateCommand;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\PaymentController;
use Msstc4Symfony\SloBundle\Test\Integration\Kernel\RoutedTestKernel;
use Override;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class GenerateCommandTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove(RoutedTestKernel::cacheRoot());
    }

    public function testWritesRulesAndDashboardsFromTheCompiledCatalogAndChecksClean(): void
    {
        $kernel = new RoutedTestKernel(
            [PaymentController::class],
            [['name' => 'payment_create', 'path' => '/payments', 'controller' => PaymentController::class, 'method' => 'create', 'methods' => ['POST']]],
            sloConfig: ['generation' => ['selector' => ['application' => 'shop'], 'grafana' => ['folder' => 'Reliability']]],
        );
        $kernel->boot();

        $output = RoutedTestKernel::cacheRoot() . '/generated';

        try {
            $command = $kernel->getContainer()->get(GenerateCommand::class);
            self::assertInstanceOf(GenerateCommand::class, $command);

            $tester = new CommandTester($command);
            self::assertSame(Command::SUCCESS, $tester->execute(['--output' => $output]));
            self::assertFileExists($output . '/prometheus/slo-recording.rules.yml');
            self::assertFileExists($output . '/grafana/dashboards/slo-slo-overview.json');
            self::assertStringContainsString('folder: Reliability', (string) file_get_contents($output . '/grafana/provisioning/dashboards/slo.yaml'));
            self::assertStringContainsString('payment.create', (string) file_get_contents($output . '/grafana/dashboards/slo-slo-overview.json'));

            self::assertSame(Command::SUCCESS, (new CommandTester($command))->execute(['--output' => $output, '--check' => true]));
        } finally {
            $kernel->shutdown();
        }
    }
}
