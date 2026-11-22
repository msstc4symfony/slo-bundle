<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Operation;

use Closure;
use Msstc4Symfony\SloBundle\Application\Logging\WarnOnceLimiter;
use Msstc4Symfony\SloBundle\Application\Port\EventRecorderInterface;
use Msstc4Symfony\SloBundle\Application\Port\ExpectedFailureClassifierInterface;
use Msstc4Symfony\SloBundle\Application\Port\MonotonicClockInterface;
use Msstc4Symfony\SloBundle\Application\Port\OperationHandleInterface;
use Msstc4Symfony\SloBundle\Application\Port\OperationMonitorInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;
use Msstc4Symfony\SloBundle\Domain\Repository\SloCatalogInterface;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

/**
 * Fail-open: never throws on its own, undeclared or invalid names get a no-op handle and one warning per name (at most 100 names are remembered per reset).
 *
 * @internal
 */
final readonly class OperationMonitor implements OperationMonitorInterface, ResetInterface
{
    private const INVALID_KEY = '(invalid)';

    private WarnOnceLimiter $warnings;

    public function __construct(
        private SloCatalogInterface $catalog,
        private EventRecorderInterface $recorder,
        private MonotonicClockInterface $clock,
        private ExpectedFailureClassifierInterface $classifier,
        private LoggerInterface $logger,
    ) {
        $this->warnings = new WarnOnceLimiter($logger, 'Further undeclared SLO operation names are suppressed until reset.');
    }

    #[Override]
    public function start(string $name): OperationHandleInterface
    {
        $key = self::INVALID_KEY;
        try {
            $key = SloName::fromString($name)->value;
            $point = PointId::of(PointType::Operation, $key);
            if ($this->catalog->forPoint($point) === [] && $this->catalog->conflictedNamesFor($point) === []) {
                $this->warnOnce($key, $name, 'SLO operation is not declared in the configuration, nothing is recorded.', null);

                return new NoopOperationHandle();
            }

            return new OperationHandle($point, $this->clock->now(), $this->clock, $this->recorder, $this->logger);
        } catch (Throwable $e) {
            $this->warnOnce($key, $name, 'SLO operation cannot be monitored, nothing is recorded.', $e);

            return new NoopOperationHandle();
        }
    }

    #[Override]
    public function measure(string $name, Closure $operation): mixed
    {
        $handle = $this->start($name);

        try {
            $result = $operation();
        } catch (Throwable $e) {
            $this->recordFailure($handle, $name, $e);

            throw $e;
        }

        $handle->end();

        return $result;
    }

    #[Override]
    public function reset(): void
    {
        $this->warnings->reset();
    }

    /**
     * Never replaces the exception of the measured operation: a failing classification counts it as an error.
     */
    private function recordFailure(OperationHandleInterface $handle, string $name, Throwable $e): void
    {
        try {
            $expected = $this->classifier->isExpectedFailure($e);
        } catch (Throwable $classificationFailure) {
            $expected = false;
            $this->logger->warning('SLO operation exception could not be classified, it counts as an error.', ['operation' => $name, 'exception' => $classificationFailure]);
        }

        if ($expected) {
            $handle->failExpected($e);
        } else {
            $handle->fail($e);
        }
        $handle->end();
    }

    /**
     * @param non-empty-literal-string $message
     */
    private function warnOnce(string $key, string $name, string $message, ?Throwable $exception): void
    {
        $this->warnings->warn($key, $message, $exception instanceof Throwable ? ['operation' => $name, 'exception' => $exception] : ['operation' => $name]);
    }
}
