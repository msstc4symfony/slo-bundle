<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Operation;

use Closure;
use InvalidArgumentException;
use Msstc4Symfony\LogicBundle\Application\Action\ExpectedFailureInterface;
use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Action as RecordEvent;
use Msstc4Symfony\SloBundle\Application\Port\ClockInterface;
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
        private readonly RecordEvent $record,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function start(string $name): OperationHandleInterface
    {
        $key = self::INVALID_KEY;
        try {
            SloName::fromString($name);
            $key = $name;
            if ($name === '') {
                throw new InvalidArgumentException('Operation name must not be empty.');
            }
            $point = PointId::of(PointType::Operation, $name);
            if ($this->catalog->forPoint($point) === [] && $this->catalog->conflictedNamesFor($point) === []) {
                $this->warnOnce($key, $name, 'SLO operation is not declared in the configuration, nothing is recorded.', null);

                return new NoopOperationHandle();
            }

            return new OperationHandle($point, $this->clock->now(), $this->clock, $this->record, $this->logger);
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
            if (interface_exists(ExpectedFailureInterface::class) && $e instanceof ExpectedFailureInterface) {
                $handle->failExpected($e);
            } else {
                $handle->fail($e);
            }
            $handle->end();

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
