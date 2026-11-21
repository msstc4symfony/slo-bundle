<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Generation\GenerateDashboards;

use Msstc4Symfony\SloBundle\Application\Generation\GeneratedFileSet;

/**
 * @internal
 */
final readonly class Output
{
    public function __construct(public GeneratedFileSet $files)
    {
    }
}
