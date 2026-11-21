<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Application\Binding;

/**
 * @internal
 */
enum MatchOperator: string
{
    case Equals = '=';
    case NotEquals = '!=';
    case Regex = '=~';
    case NotRegex = '!~';
}
