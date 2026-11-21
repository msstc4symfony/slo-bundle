<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\ValueObject;

use InvalidArgumentException;

final readonly class Classification
{
    public function __construct(
        public StatusSet $badStatuses,
        public StatusSet $ineligibleStatuses,
        public bool $expectedFailureIsGood,
        public bool $countAttempts,
    ) {
        if ($badStatuses->intersects($ineligibleStatuses)) {
            throw new InvalidArgumentException(\sprintf('Bad statuses "%s" overlap ineligible statuses "%s".', $badStatuses->toRegex(), $ineligibleStatuses->toRegex()));
        }
    }

    public function equals(self $other): bool
    {
        return $this->badStatuses->canonical() === $other->badStatuses->canonical()
            && $this->ineligibleStatuses->canonical() === $other->ineligibleStatuses->canonical()
            && $this->expectedFailureIsGood === $other->expectedFailureIsGood
            && $this->countAttempts === $other->countAttempts;
    }

    public static function defaults(): self
    {
        return new self(StatusSet::fromList(['5xx']), StatusSet::fromList([]), true, false);
    }
}
