<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Binding;

use Msstc4Symfony\SloBundle\Domain\Enum\ObjectiveKind;
use Msstc4Symfony\SloBundle\Domain\ValueObject\SloName;

/**
 * One objective of one SLO, "<slo>|<kind>": keys the revisions precomputed at warm-up and the per-process memos of
 * the request path. The separator cannot occur in either part (SloName and ObjectiveKind values exclude "|").
 *
 * @internal
 */
final readonly class ObjectiveKey
{
    /**
     * @param non-empty-string $value
     */
    private function __construct(public string $value)
    {
    }

    public static function of(SloName $name, ObjectiveKind $kind): self
    {
        return new self($name->value . '|' . $kind->value);
    }
}
