<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\Enum;

enum EventResult: string
{
    case Good = 'good';
    case Bad = 'bad';
}
