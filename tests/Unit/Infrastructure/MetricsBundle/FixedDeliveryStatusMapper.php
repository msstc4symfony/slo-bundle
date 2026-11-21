<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Infrastructure\MetricsBundle;

use Msstc4Symfony\SloBundle\Domain\Enum\DeliveryStatus;
use Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle\DeliveryStatusMapperInterface;
use Override;
use RuntimeException;

/**
 * Answers every status with the given one (null: an unknown status); throws instead when $throws is true.
 */
final readonly class FixedDeliveryStatusMapper implements DeliveryStatusMapperInterface
{
    public function __construct(private ?DeliveryStatus $answer, private bool $throws = false)
    {
    }

    #[Override]
    public function map(string $status): ?DeliveryStatus
    {
        if ($this->throws) {
            throw new RuntimeException('mapper broken');
        }

        return $this->answer;
    }
}
