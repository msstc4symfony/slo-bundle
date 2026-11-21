<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Fixtures\Controller;

final class CoverageController
{
    public function plain(): string
    {
        return 'plain';
    }

    public function panel(): string
    {
        return 'panel';
    }
}
