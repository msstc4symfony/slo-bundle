<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\Grafana;

use InvalidArgumentException;

/**
 * A panel without `id` and `gridPos`: DashboardBuilder assigns both while laying the dashboard out.
 */
final readonly class Panel
{
    public const GRID_COLUMNS = 24;

    /**
     * @param array<string, mixed> $json the panel as Grafana JSON
     * @param int<1, 24> $width in grid columns
     * @param positive-int $height in grid rows
     */
    public function __construct(
        public array $json,
        public int $width,
        public int $height,
    ) {
        if ($height < 1) {
            throw new InvalidArgumentException(\sprintf('Panel size %dx%d is outside the %d column grid.', $width, $height, self::GRID_COLUMNS));
        }
    }
}
