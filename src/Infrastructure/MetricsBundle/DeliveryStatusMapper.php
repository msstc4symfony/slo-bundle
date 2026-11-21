<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle;

use Msstc4Symfony\SloBundle\Domain\Enum\DeliveryStatus;
use Override;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * A status added by a later metrics-bundle is skipped, not guessed; warned once per status until kernel.reset.
 *
 * @internal
 */
final class DeliveryStatusMapper implements DeliveryStatusMapperInterface, ResetInterface
{
    /** @var array<string, true> */
    private array $warned = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    #[Override]
    public function map(string $status): ?DeliveryStatus
    {
        $delivery = DeliveryStatus::tryFrom($status);
        if (!$delivery instanceof DeliveryStatus && !isset($this->warned[$status])) {
            $this->warned[$status] = true;
            $this->logger->warning('Unknown Messenger delivery status, its SLO events are not recorded.', ['status' => $status]);
        }

        return $delivery;
    }

    #[Override]
    public function reset(): void
    {
        $this->warned = [];
    }
}
