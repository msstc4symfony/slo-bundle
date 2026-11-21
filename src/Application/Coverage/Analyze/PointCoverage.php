<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Coverage\Analyze;

use Msstc4Symfony\SloBundle\Domain\Enum\CoverageStatus;

final readonly class PointCoverage
{
    public const KIND_HTTP_ROUTE = 'http_route';

    public const KIND_MESSENGER_HANDLER = 'messenger_handler';

    public const KIND_LOGIC_ACTION = 'logic_action';

    public const KIND_OPERATION = 'operation';

    public const KINDS = [
        self::KIND_HTTP_ROUTE,
        self::KIND_MESSENGER_HANDLER,
        self::KIND_LOGIC_ACTION,
        self::KIND_OPERATION,
    ];

    /**
     * @param non-empty-string $point route name, handler "Class::method", logic action id or operation name
     * @param self::KIND_* $kind
     * @param non-empty-string|null $reason set for every status except monitored
     * @param list<non-empty-string> $slos names of the SLOs covering the point, sorted
     * @param bool $measuredByUniversalMetric the point is already measured by a metrics-bundle series, with or without an SLO
     */
    public function __construct(
        public string $point,
        public string $kind,
        public CoverageStatus $status,
        public ?string $reason,
        public array $slos,
        public bool $measuredByUniversalMetric,
    ) {
    }
}
