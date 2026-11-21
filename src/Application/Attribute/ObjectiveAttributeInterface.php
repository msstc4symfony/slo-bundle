<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Attribute;

interface ObjectiveAttributeInterface
{
    /**
     * @return 'availability'|'latency'|'combined'
     */
    public function kind(): string;
}
