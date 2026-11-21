<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards;

use Msstc4Symfony\SloBundle\Application\Generation\Selector;

/**
 * @internal
 */
final readonly class Input
{
    public function __construct(public Selector $selector)
    {
    }
}
