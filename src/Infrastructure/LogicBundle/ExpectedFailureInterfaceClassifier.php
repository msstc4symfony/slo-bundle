<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\LogicBundle;

use Msstc4Symfony\LogicBundle\Application\Action\ExpectedFailureInterface;
use Msstc4Symfony\SloBundle\Application\Port\ExpectedFailureClassifierInterface;
use Override;
use Throwable;

/**
 * For logic-bundle releases before ActionOutcome: only ExpectedFailureInterface marks an expected failure.
 *
 * @internal
 */
final class ExpectedFailureInterfaceClassifier implements ExpectedFailureClassifierInterface
{
    #[Override]
    public function isExpectedFailure(Throwable $e): bool
    {
        return $e instanceof ExpectedFailureInterface;
    }
}
