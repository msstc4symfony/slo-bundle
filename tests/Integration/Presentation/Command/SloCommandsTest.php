<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Presentation\Command;

use Closure;
use Msstc4Symfony\SloBundle\Presentation\Command\ListCommand;
use Msstc4Symfony\SloBundle\Presentation\Command\ValidateCommand;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\ConflictingSloController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\InvokableController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\PaymentController;
use Msstc4Symfony\SloBundle\Test\Fixtures\Controller\SharedSloController;
use Msstc4Symfony\SloBundle\Test\Integration\Kernel\RoutedTestKernel;
use Override;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @phpstan-import-type RouteSpec from RoutedTestKernel
 */
final class SloCommandsTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        (new Filesystem())->remove(RoutedTestKernel::cacheRoot());
    }

    public function testConflictingDeclarationsFailValidationAndShowTwoDuplicateRows(): void
    {
        $kernel = new RoutedTestKernel(
            [ConflictingSloController::class],
            [
                ['name' => 'payment_create', 'path' => '/payments', 'controller' => ConflictingSloController::class, 'method' => 'create'],
                ['name' => 'payment_create_v2', 'path' => '/v2/payments', 'controller' => ConflictingSloController::class, 'method' => 'createV2'],
            ],
        );

        $this->withKernel($kernel, function (ValidateCommand $validate, ListCommand $list): void {
            $validated = new CommandTester($validate);
            self::assertSame(Command::FAILURE, $validated->execute([]));
            $display = $validated->getDisplay();
            self::assertStringContainsString(ConflictingSloController::class . '::create', $display);
            self::assertStringContainsString(ConflictingSloController::class . '::createV2', $display);
            self::assertStringContainsString('target 99.9 vs 99.5', $display);
            self::assertStringNotContainsString('OK', $display);

            $listed = new CommandTester($list);
            self::assertSame(Command::SUCCESS, $listed->execute([]));
            self::assertSame(2, substr_count($listed->getDisplay(), 'DUPLICATE'));
            self::assertStringContainsString('payment_create_v2', $listed->getDisplay());
        });
    }

    public function testIdenticalSharedDeclarationsPassWithANotice(): void
    {
        $kernel = new RoutedTestKernel(
            [SharedSloController::class],
            [
                ['name' => 'shared_create', 'path' => '/s', 'controller' => SharedSloController::class, 'method' => 'create'],
                ['name' => 'shared_create_v2', 'path' => '/s2', 'controller' => SharedSloController::class, 'method' => 'createV2'],
            ],
        );

        $this->withKernel($kernel, function (ValidateCommand $validate, ListCommand $list): void {
            $validated = new CommandTester($validate);
            self::assertSame(Command::SUCCESS, $validated->execute([]));
            self::assertStringContainsString('shared by 2 points', $validated->getDisplay());
            self::assertStringContainsString('OK', $validated->getDisplay());

            $listed = new CommandTester($list);
            $listed->execute([]);
            self::assertStringContainsString('shared by 2 points', $listed->getDisplay());
        });
    }

    public function testControllerWithSloAndNoRouteFailsValidation(): void
    {
        $kernel = new RoutedTestKernel([PaymentController::class], []);

        $this->withKernel($kernel, function (ValidateCommand $validate): void {
            $tester = new CommandTester($validate);

            self::assertSame(Command::FAILURE, $tester->execute([]));
            self::assertStringContainsString('SLO payment.create: ' . PaymentController::class . '::create has no route', $tester->getDisplay());
        });
    }

    public function testRouteIgnoredByMetricsBundleFailsValidation(): void
    {
        $kernel = new RoutedTestKernel(
            [InvokableController::class],
            [['name' => 'refund', 'path' => '/refunds', 'controller' => InvokableController::class]],
            ['refund'],
        );

        $this->withKernel($kernel, function (ValidateCommand $validate): void {
            $tester = new CommandTester($validate);

            self::assertSame(Command::FAILURE, $tester->execute([]));
            self::assertStringContainsString('route refund', $tester->getDisplay());
        });
    }

    public function testCleanCatalogPrintsOkAndListsEveryRouteOfAnSlo(): void
    {
        $kernel = $this->paymentsKernel();

        $this->withKernel($kernel, function (ValidateCommand $validate, ListCommand $list): void {
            $validated = new CommandTester($validate);
            self::assertSame(Command::SUCCESS, $validated->execute([]));
            self::assertStringContainsString('OK', $validated->getDisplay());

            $listed = new CommandTester($list);
            $listed->execute([]);
            self::assertStringContainsString('payment_create', $listed->getDisplay());
            self::assertStringContainsString('payment_update', $listed->getDisplay());
            self::assertStringContainsString('refund_create', $listed->getDisplay());
        });
    }

    public function testJsonFormatIsStableAndSorted(): void
    {
        $this->withKernel($this->paymentsKernel(), function (ValidateCommand $validate, ListCommand $list): void {
            $first = new CommandTester($list);
            $first->execute(['--format' => 'json']);

            $second = new CommandTester($list);
            $second->execute(['--format' => 'json']);

            self::assertSame($first->getDisplay(), $second->getDisplay());
            $rows = json_decode($first->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(['payment.combined', 'payment.create', 'refund.create'], $this->pluck($rows, 'name'));
            $create = $this->pluck($rows, null)[1];
            self::assertSame('ok', $this->pluck([$create], 'status')[0]);
            self::assertSame([], $this->pluck([$create], 'shared_points')[0]);
            $objectives = $this->pluck([$create], 'objectives')[0];
            self::assertSame(['availability', 'latency'], $this->pluck($objectives, 'kind'));
            $points = $this->pluck([$create], 'points')[0];
            self::assertSame(['payment_create', 'payment_update'], $this->pluck($points, 'routes')[0]);
            self::assertSame(['kind', 'revision', 'strategy', 'target', 'threshold'], $this->sortedKeys($this->pluck($objectives, null)[0]));
            self::assertSame(['existing_series', 'dedicated'], $this->pluck($objectives, 'strategy'));
        });
    }

    public function testTableCellsAreNotInterpretedAsConsoleMarkup(): void
    {
        $kernel = new RoutedTestKernel(
            [InvokableController::class],
            [['name' => 'refund<error>x</error>', 'path' => '/refunds', 'controller' => InvokableController::class]],
        );

        $this->withKernel($kernel, function (ValidateCommand $validate, ListCommand $list): void {
            $tester = new CommandTester($list);
            $tester->execute([], ['decorated' => true]);

            self::assertStringContainsString('refund<error>x</error>', $tester->getDisplay());
        });
    }

    public function testJsonIsWrittenRawSoMarkupLikeValuesSurvive(): void
    {
        $kernel = new RoutedTestKernel(
            [InvokableController::class],
            [['name' => 'refund<error>x</error>', 'path' => '/refunds', 'controller' => InvokableController::class]],
        );

        $this->withKernel($kernel, function (ValidateCommand $validate, ListCommand $list): void {
            $tester = new CommandTester($list);
            $tester->execute(['--format' => 'json'], ['decorated' => true]);

            self::assertStringContainsString('refund<error>x</error>', $tester->getDisplay());
        });
    }

    public function testUnknownFormatIsRejected(): void
    {
        $this->withKernel($this->paymentsKernel(), function (ValidateCommand $validate, ListCommand $list): void {
            $tester = new CommandTester($list);

            self::assertSame(Command::INVALID, $tester->execute(['--format' => 'yaml']));
        });
    }

    /**
     * @return list<mixed> the key of every item (the items themselves for null)
     */
    private function pluck(mixed $items, ?string $key): array
    {
        self::assertIsArray($items);

        return array_values(array_map(static function (mixed $item) use ($key): mixed {
            if ($key === null) {
                return $item;
            }
            self::assertIsArray($item);

            return $item[$key];
        }, $items));
    }

    /**
     * @return list<array-key>
     */
    private function sortedKeys(mixed $row): array
    {
        self::assertIsArray($row);
        $keys = array_keys($row);
        sort($keys);

        return $keys;
    }

    private function paymentsKernel(): RoutedTestKernel
    {
        return new RoutedTestKernel(
            [PaymentController::class, InvokableController::class],
            [
                ['name' => 'payment_create', 'path' => '/payments', 'controller' => PaymentController::class, 'method' => 'create', 'methods' => ['POST']],
                ['name' => 'payment_update', 'path' => '/payments/{id}', 'controller' => PaymentController::class, 'method' => 'create', 'methods' => ['PUT']],
                ['name' => 'payment_health', 'path' => '/payments/health', 'controller' => PaymentController::class, 'method' => 'health'],
                ['name' => 'refund_create', 'path' => '/refunds', 'controller' => InvokableController::class],
            ],
        );
    }

    /**
     * @param Closure(ValidateCommand, ListCommand): void $test
     */
    private function withKernel(RoutedTestKernel $kernel, Closure $test): void
    {
        $kernel->boot();

        try {
            $container = $kernel->getContainer();
            $validate = $container->get(ValidateCommand::class);
            $list = $container->get(ListCommand::class);
            self::assertInstanceOf(ValidateCommand::class, $validate);
            self::assertInstanceOf(ListCommand::class, $list);
            $test($validate, $list);
        } finally {
            $kernel->shutdown();
        }
    }
}
