<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Discovery;

use Msstc4Symfony\SloBundle\Domain\ValueObject\PointId;

/**
 * @internal
 */
final readonly class TaggedPoint
{
    /**
     * @param non-empty-string|null $metricLabel value of the point's label in the bridge's own duration metric
     */
    public function __construct(
        public PointId $point,
        public ?string $metricLabel,
    ) {
    }
}
