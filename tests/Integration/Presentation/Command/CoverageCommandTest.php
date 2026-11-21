<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Presentation\Command;

use Closure;
use JsonSchema\Constraints\Constraint;
use JsonSchema\Validator;
use Msstc4Symfony\SloBundle\Presentation\Command\CoverageCommand;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\ConflictingSloController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\CoverageController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\PaymentController;
use Msstc4Symfony\SloBundle\Test\Integration\Kernel\RoutedTestKernel;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

final class CoverageCommandTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove(RoutedTestKernel::cacheRoot());
    }

    public function testDefaultThresholdFailsAtFiftyPercentAndListsEveryNonMonitoredPoint(): void
    {
        $this->withCommand($this->kernel(), function (CoverageCommand $command): void {
            $tester = new CommandTester($command);

            self::assertSame(Command::FAILURE, $tester->execute([]));
            $display = $tester->getDisplay();
            self::assertStringContainsString('ignored', $display);
            self::assertStringContainsString('health probe', $display);
            self::assertStringContainsString('_profiler', $display);
            self::assertStringContainsString('system route', $display);
            self::assertStringContainsString('unmonitored', $display);
            self::assertStringContainsString('plain', $display);
            self::assertStringNotContainsString('payment_create', $display);
            self::assertStringContainsString('http_route', $display);
            self::assertStringContainsString('50.00%', $display);
        });
    }

    public function testMinOverridesTheThreshold(): void
    {
        $this->withCommand($this->kernel(), function (CoverageCommand $command): void {
            $tester = new CommandTester($command);

            self::assertSame(Command::FAILURE, $tester->execute(['--min' => '95']));
            self::assertSame(Command::SUCCESS, $tester->execute(['--min' => '50']));
            self::assertSame(Command::SUCCESS, $tester->execute(['--min' => '0']));
            self::assertSame(Command::FAILURE, $tester->execute(['--min' => '50.01']));
            self::assertMatchesRegularExpression('/^http_route +SLO 50\.00%, measured by universal metrics [0-9.]+%, required 50\.01% BELOW \((\d+) of (\d+) eligible points have an SLO\)$/m', $tester->getDisplay(), 'the exact fraction explains a rounded percent that looks like the requirement');
        });
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidMinProvider(): iterable
    {
        yield 'text' => ['abc'];
        yield 'above 100' => ['100.5'];
        yield 'negative' => ['-1'];
        yield 'not a number' => ['NAN'];
        yield 'infinity' => ['INF'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidMinProvider')]
    public function testInvalidMinExitsWithTwo(string $min): void
    {
        $this->withCommand($this->kernel(), function (CoverageCommand $command) use ($min): void {
            $tester = new CommandTester($command);

            self::assertSame(Command::INVALID, $tester->execute(['--min' => $min]));
            self::assertStringContainsString('--min', $tester->getDisplay());
        });
    }

    public function testUnknownFormatIsRejected(): void
    {
        $this->withCommand($this->kernel(), function (CoverageCommand $command): void {
            self::assertSame(Command::INVALID, (new CommandTester($command))->execute(['--format' => 'yaml']));
        });
    }

    public function testInvalidPointFailsTheGateEvenWhenEveryThresholdIsMet(): void
    {
        $kernel = new RoutedTestKernel(
            [ConflictingSloController::class],
            [
                ['name' => 'payment_create', 'path' => '/payments', 'controller' => ConflictingSloController::class, 'method' => 'create'],
                ['name' => 'payment_create_v2', 'path' => '/v2/payments', 'controller' => ConflictingSloController::class, 'method' => 'createV2'],
            ],
        );

        $this->withCommand($kernel, function (CoverageCommand $command): void {
            $tester = new CommandTester($command);

            self::assertSame(Command::FAILURE, $tester->execute(['--min' => '0']));
            self::assertStringContainsString('invalid', $tester->getDisplay());
        });
    }

    public function testJsonIsStableValidAgainstTheSchemaAndWrittenRaw(): void
    {
        $this->withCommand($this->kernel(), function (CoverageCommand $command): void {
            $first = new CommandTester($command);
            $first->execute(['--format' => 'json', '--min' => '50'], ['decorated' => true]);

            $second = new CommandTester($command);
            $second->execute(['--format' => 'json', '--min' => '50'], ['decorated' => true]);

            self::assertSame($first->getDisplay(), $second->getDisplay());
            self::assertStringEndsWith("}\n", $first->getDisplay());
            self::assertStringContainsString('_profiler', $first->getDisplay());
            self::assertStringNotContainsString('\/', $first->getDisplay());

            $document = json_decode($first->getDisplay(), false, flags: JSON_THROW_ON_ERROR);
            $validator = new Validator();
            $validator->validate($document, (object) ['$ref' => 'file://' . \dirname(__DIR__, 4) . '/doc/coverage-schema.json'], Constraint::CHECK_MODE_NORMAL);
            self::assertTrue($validator->isValid(), (string) json_encode($validator->getErrors()));

            $decoded = json_decode($first->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertSame(0, $decoded['invalid']);
            self::assertTrue($decoded['passed']);
            self::assertIsArray($decoded['slo_percent']);
            self::assertEquals(50.0, $decoded['slo_percent']['http_route']);
            self::assertIsArray($decoded['required_percent']);
            self::assertEquals(50.0, $decoded['required_percent']['http_route']);
        });
    }

    public function testJsonReportsAFailedGate(): void
    {
        $this->withCommand($this->kernel(), function (CoverageCommand $command): void {
            $tester = new CommandTester($command);

            self::assertSame(Command::FAILURE, $tester->execute(['--format' => 'json']));
            $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            self::assertFalse($decoded['passed']);
            self::assertIsArray($decoded['required_percent']);
            self::assertEquals(95.0, $decoded['required_percent']['http_route']);
        });
    }

    public function testSchemaRejectsMalformedReports(): void
    {
        $valid = [
            'invalid' => 0,
            'measured_percent' => ['http_route' => 100],
            'passed' => true,
            'points' => [['kind' => 'http_route', 'measured_by_universal_metric' => true, 'point' => 'a', 'reason' => null, 'slos' => ['a.b'], 'status' => 'monitored']],
            'required_percent' => ['http_route' => 95],
            'slo_percent' => ['http_route' => 100],
        ];
        self::assertTrue($this->conforms($valid));

        $badStatus = $valid;
        $badStatus['points'][0]['status'] = 'bogus';
        $monitoredWithReason = $valid;
        $monitoredWithReason['points'][0]['reason'] = 'why';
        $unmonitoredWithoutReason = $valid;
        $unmonitoredWithoutReason['points'][0]['status'] = 'unmonitored';
        $overHundred = $valid;
        $overHundred['slo_percent']['http_route'] = 100.5;
        $extraKey = $valid;
        $extraKey['generated_at'] = '2026-11-21T00:00:00Z';
        $missingKey = $valid;
        unset($missingKey['passed']);

        foreach (['bad status' => $badStatus, 'monitored with reason' => $monitoredWithReason, 'unmonitored without reason' => $unmonitoredWithoutReason, 'over 100' => $overHundred, 'extra key' => $extraKey, 'missing key' => $missingKey] as $case => $document) {
            self::assertFalse($this->conforms($document), $case);
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    private function conforms(array $document): bool
    {
        $validator = new Validator();
        $data = json_decode(json_encode($document, JSON_THROW_ON_ERROR), false, flags: JSON_THROW_ON_ERROR);
        $validator->validate($data, (object) ['$ref' => 'file://' . \dirname(__DIR__, 4) . '/doc/coverage-schema.json']);

        return $validator->isValid();
    }

    private function kernel(): RoutedTestKernel
    {
        return new RoutedTestKernel(
            [PaymentController::class, CoverageController::class],
            [
                ['name' => 'payment_create', 'path' => '/payments', 'controller' => PaymentController::class, 'method' => 'create', 'methods' => ['POST']],
                ['name' => 'payment_health', 'path' => '/payments/health', 'controller' => PaymentController::class, 'method' => 'health'],
                ['name' => '_profiler', 'path' => '/_profiler', 'controller' => CoverageController::class, 'method' => 'panel'],
                ['name' => 'plain', 'path' => '/plain', 'controller' => CoverageController::class, 'method' => 'plain'],
            ],
        );
    }

    /**
     * @param Closure(CoverageCommand): void $test
     */
    private function withCommand(RoutedTestKernel $kernel, Closure $test): void
    {
        $kernel->boot();

        try {
            $command = $kernel->getContainer()->get(CoverageCommand::class);
            self::assertInstanceOf(CoverageCommand::class, $command);
            $test($command);
        } finally {
            $kernel->shutdown();
        }
    }
}
