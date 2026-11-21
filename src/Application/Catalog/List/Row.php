<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Catalog\List;

final readonly class Row
{
    public const STATUS_OK = 'ok';

    public const STATUS_DUPLICATE = 'duplicate';

    /**
     * @param non-empty-string $name
     * @param self::STATUS_* $status
     * @param non-empty-string $pointType
     * @param non-empty-string $window as declared
     * @param non-empty-list<ObjectiveRow> $objectives sorted by kind
     * @param non-empty-list<PointRow> $points sorted by point
     * @param list<non-empty-string> $sharedPoints all points of an SLO merged from identical declarations, otherwise empty
     */
    public function __construct(
        public string $name,
        public string $status,
        public string $pointType,
        public string $window,
        public array $objectives,
        public array $points,
        public array $sharedPoints,
    ) {
    }
}
