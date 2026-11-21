<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\LogicBundle;

use Msstc4Symfony\SloBundle\Application\Port\ExpectedFailureClassifierInterface;
use Override;
use Throwable;

/**
 * Used when logic-bundle is not installed: every exception of a measured operation is an error.
 *
 * @internal
 */
final class NullExpectedFailureClassifier implements ExpectedFailureClassifierInterface
{
    #[Override]
    public function isExpectedFailure(Throwable $e): bool
    {
        return false;
    }
}
