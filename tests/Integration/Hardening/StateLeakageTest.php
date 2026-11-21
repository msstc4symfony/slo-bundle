<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Integration\Hardening;

use Monolog\Level;
use Monolog\LogRecord;
use Msstc4Symfony\SloBundle\Application\Port\OperationMonitorInterface;
use Msstc4Symfony\SloBundle\Test\Fixtures\Runtime\CheckoutController;

/**
 * RoadRunner-style worker: one booted kernel, services reset between requests.
 */
final class StateLeakageTest extends HardeningTestCase
{
    public function testSequentialRequestsAreAttributedToTheirOwnSlo(): void
    {
        $this->boot();

        $this->serve('/checkout-fragment');
        $this->resetServices();
        $this->serve('/checkout/throw');
        $this->resetServices();
        $this->serve('/checkout/ok');
        $this->resetServices();
        $this->serve('/checkout/not-found');

        self::assertSame([
            CheckoutController::FRAGMENT_SLO . '|combined|good' => 1,
            CheckoutController::PAGE_SLO . '|combined|bad' => 1,
            CheckoutController::PAGE_SLO . '|combined|good' => 2,
            CheckoutController::PAGE_SLO . '|latency|good' => 2,
        ], $this->counts());
    }

    public function testOperationWarningSetIsClearedByReset(): void
    {
        $this->boot();
        $monitor = self::getContainer()->get(OperationMonitorInterface::class);
        self::assertInstanceOf(OperationMonitorInterface::class, $monitor);

        $monitor->start('undeclared.operation');
        $monitor->start('undeclared.operation');
        self::assertCount(1, $this->undeclaredWarnings());

        $this->resetServices();
        $monitor->start('undeclared.operation');

        self::assertCount(2, $this->undeclaredWarnings());
    }

    /**
     * @return list<LogRecord>
     */
    private function undeclaredWarnings(): array
    {
        return array_values(array_filter(
            $this->logHandler()->getRecords(),
            static fn (LogRecord $record): bool => $record->level === Level::Warning && str_contains($record->message, 'is not declared in the configuration'),
        ));
    }
}
