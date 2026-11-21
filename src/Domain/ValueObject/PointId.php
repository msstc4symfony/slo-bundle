<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\ValueObject;

use InvalidArgumentException;
use Msstc4Symfony\SloBundle\Domain\Enum\PointType;

final readonly class PointId
{
    /**
     * @param non-empty-string $id
     */
    private function __construct(public PointType $type, public string $id)
    {
    }

    /**
     * @param non-empty-string $id
     */
    public static function of(PointType $type, string $id): self
    {
        if ($id === '') {
            throw new InvalidArgumentException(\sprintf('Point id of type "%s" must not be empty.', $type->value));
        }

        return new self($type, $id);
    }

    /**
     * @return non-empty-string
     */
    public function toString(): string
    {
        return $this->type->value . ':' . $this->id;
    }

    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
