<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Operation;

use Msstc4Symfony\SloBundle\Application\Evaluation\RecordEvent\Input;
use Msstc4Symfony\SloBundle\Application\Port\EventRecorderInterface;
use Msstc4Symfony\SloBundle\Application\Port\MonotonicClockInterface;
use Msstc4Symfony\SloBundle\Application\Port\OperationHandleInterface;
use Msstc4Symfony\SloBundle\Domain\Enum\Outcome;
use Msstc4Symfony\SloBundle\Domain\ValueObject\Observation;
use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;
use Override;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * There is deliberately no destructor: an operation that is never ended is not recorded.
 *
 * @internal
 */
final class OperationHandle implements OperationHandleInterface
{
    private ?Outcome $outcome = null;

    private bool $ended = false;

    /**
     * @param int $startedAt nanoseconds on the injected clock
     */
    public function __construct(
        private readonly PointId $point,
        private readonly int $startedAt,
        private readonly MonotonicClockInterface $clock,
        private readonly EventRecorderInterface $recorder,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Override]
    public function succeed(): void
    {
        $this->outcome ??= Outcome::Success;
    }

    #[Override]
    public function failExpected(?Throwable $reason = null): void
    {
        $this->outcome ??= Outcome::ExpectedFailure;
    }

    #[Override]
    public function fail(?Throwable $reason = null): void
    {
        $this->outcome ??= Outcome::Error;
    }

    #[Override]
    public function end(): void
    {
        if ($this->ended) {
            return;
        }
        $this->ended = true;

        try {
            $elapsed = max(0, $this->clock->now() - $this->startedAt) / 1_000_000_000;
            $this->recorder->record(new Input($this->point, Observation::outcome($this->point->type, $this->outcome ?? Outcome::Success, $elapsed)));
        } catch (Throwable $e) {
            $this->logger->warning('SLO operation event was not recorded.', ['operation' => $this->point->id, 'exception' => $e]);
        }
    }
}
