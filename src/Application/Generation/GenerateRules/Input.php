<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\GenerateRules;

use Msstc4Symfony\SloBundle\Application\Generation\Selector;

/**
 * @internal
 */
final readonly class Input
{
    /**
     * @param positive-int|null $noEventsAlertHours
     */
    public function __construct(
        public Selector $selector,
        public bool $recordingRules = true,
        public bool $alertRules = true,
        public ?int $noEventsAlertHours = null,
    ) {
    }
}
