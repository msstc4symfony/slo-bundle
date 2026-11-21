<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Operation;

use Closure;
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
final class OperationMonitor implements OperationMonitorInterface, ResetInterface
{
    private const MAX_WARNED_NAMES = 100;

    private const INVALID_KEY = '(invalid)';

    /** @var array<string, true> */
    private array $warned = [];

    private bool $suppressionWarned = false;

    public function __construct(
        private readonly SloCatalogInterface $catalog,
        private readonly EventRecorderInterface $recorder,
        private readonly MonotonicClockInterface $clock,
        private readonly ExpectedFailureClassifierInterface $classifier,
        private readonly LoggerInterface $logger,
    ) {
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
            $this->recordFailure($handle, $e);

            throw $e;
        }

        $handle->end();

        return $result;
    }

    #[Override]
    public function reset(): void
    {
        $this->warned = [];
        $this->suppressionWarned = false;
    }

    /**
     * Never replaces the exception of the measured operation: a failing classification counts it as an error.
     */
    private function recordFailure(OperationHandleInterface $handle, Throwable $e): void
    {
        try {
            $expected = $this->classifier->isExpectedFailure($e);
        } catch (Throwable $classificationFailure) {
            $expected = false;
            $this->logger->warning('SLO operation exception could not be classified, it counts as an error.', ['exception' => $classificationFailure]);
        }

        if ($expected) {
            $handle->failExpected($e);
        } else {
            $handle->fail($e);
        }
        $handle->end();
    }

    private function warnOnce(string $key, string $name, string $message, ?Throwable $exception): void
    {
        if (isset($this->warned[$key])) {
            return;
        }
        if (\count($this->warned) >= self::MAX_WARNED_NAMES) {
            if (!$this->suppressionWarned) {
                $this->suppressionWarned = true;
                $this->logger->warning('Further undeclared SLO operation names are suppressed until reset.');
            }

            return;
        }
        $this->warned[$key] = true;
        $this->logger->warning($message, $exception instanceof Throwable ? ['operation' => $name, 'exception' => $exception] : ['operation' => $name]);
    }
}
