<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Attribute;

use Attribute;

/**
 * Holds raw declared values; validation happens in the discovery pass so all errors are collected together.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class Slo
{
    /**
     * @param non-empty-list<ObjectiveAttributeInterface> $objectives
     * @param list<int|string>|null $badStatuses
     * @param list<int|string>|null $ineligibleStatuses
     * @param 'auto'|'dedicated' $strategy
     */
    public function __construct(
        public string $name,
        public array $objectives,
        public ?string $window = null,
        public ?array $badStatuses = null,
        public ?array $ineligibleStatuses = null,
        public ?bool $expectedFailureIsGood = null,
        public ?bool $countAttempts = null,
        public string $strategy = 'auto',
    ) {
    }
}
