<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\MetricsBundle;

use Msstc4Symfony\SloBundle\Domain\Enum\DeliveryStatus;

/**
 * @internal
 */
interface DeliveryStatusMapperInterface
{
    /**
     * @return DeliveryStatus|null null for a status this bundle does not know (a newer metrics-bundle)
     */
    public function map(string $status): ?DeliveryStatus;
}
