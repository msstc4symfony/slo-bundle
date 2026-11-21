<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Test\Unit\Application\Operation;

use LogicException;
use Msstc4Symfony\SloBundle\Application\Port\ExpectedFailureClassifierInterface;
use Override;
use Throwable;

final class ThrowingClassifier implements ExpectedFailureClassifierInterface
{
    #[Override]
    public function isExpectedFailure(Throwable $e): bool
    {
        throw new LogicException('classifier broken');
    }
}
