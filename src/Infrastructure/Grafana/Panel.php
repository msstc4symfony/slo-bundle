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
     * @param int $width in grid columns, 1..GRID_COLUMNS (checked at runtime)
     * @param int $height in grid rows, at least 1 (checked at runtime)
     */
    public function __construct(
        public array $json,
        public int $width,
        public int $height,
    ) {
        if ($width < 1 || $width > self::GRID_COLUMNS || $height < 1) {
            throw new InvalidArgumentException(\sprintf('Panel size %dx%d is outside the %d column grid.', $width, $height, self::GRID_COLUMNS));
        }
    }
}
