<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Controller\Invalid;

use Msstc4Symfony\SloBundle\Application\Attribute\IgnoreMonitoring;

final class BlankIgnoreReasonController
{
    #[IgnoreMonitoring('  ')]
    public function debug(): string
    {
        return 'debug';
    }
}
