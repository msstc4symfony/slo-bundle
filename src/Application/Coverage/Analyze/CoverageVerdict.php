<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Coverage\Analyze;

use Msstc4Symfony\SloBundle\Domain\Enum\CoverageStatus;

/**
 * Status of a point and why. Precedence: invalid, ignored, unsupported, then monitored or unmonitored.
 *
 * @internal
 */
final readonly class CoverageVerdict
{
    /**
     * @param non-empty-string|null $reason null for monitored points only
     */
    private function __construct(
        public CoverageStatus $status,
        public ?string $reason,
    ) {
    }

    /**
     * @param non-empty-string|null $invalid
     * @param non-empty-string|null $ignored
     * @param non-empty-string|null $unsupported
     */
    public static function decide(bool $covered, ?string $invalid = null, ?string $ignored = null, ?string $unsupported = null): self
    {
        return match (true) {
            $invalid !== null => new self(CoverageStatus::Invalid, $invalid),
            $ignored !== null => new self(CoverageStatus::Ignored, $ignored),
            $unsupported !== null => new self(CoverageStatus::Unsupported, $unsupported),
            $covered => new self(CoverageStatus::Monitored, null),
            default => new self(CoverageStatus::Unmonitored, 'no SLO is declared'),
        };
    }
}
