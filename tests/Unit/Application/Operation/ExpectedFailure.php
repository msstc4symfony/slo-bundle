<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Operation;

use Msstc4Symfony\LogicBundle\Application\Action\ExpectedFailureInterface;
use RuntimeException;

final class ExpectedFailure extends RuntimeException implements ExpectedFailureInterface
{
}
