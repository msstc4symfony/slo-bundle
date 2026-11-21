<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Attribute;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class IgnoreMonitoring
{
    public function __construct(public string $reason)
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('IgnoreMonitoring reason must not be blank.');
        }
    }
}
