<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Domain\Enum;

enum Outcome: string
{
    case Success = 'success';
    case ExpectedFailure = 'expected_failure';
    case Error = 'error';
}
