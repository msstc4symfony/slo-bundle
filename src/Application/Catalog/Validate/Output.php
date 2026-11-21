<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Catalog\Validate;

final readonly class Output
{
    /**
     * @param list<non-empty-string> $errors fail the validation
     * @param list<non-empty-string> $warnings never fail the validation
     * @param list<non-empty-string> $notices informational, never fail the validation
     */
    public function __construct(
        public array $errors,
        public array $warnings,
        public array $notices = [],
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
