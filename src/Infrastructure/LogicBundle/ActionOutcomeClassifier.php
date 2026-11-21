<?php

declare(strict_types=1);

namespace Msstc4Symfony\SloBundle\Infrastructure\LogicBundle;

use Msstc4Symfony\LogicBundle\Application\Action\ActionOutcome;
use Msstc4Symfony\SloBundle\Application\Port\ExpectedFailureClassifierInterface;
use Override;
use Throwable;

/**
 * Classifies as logic-bundle classifies its Actions (spec 5.3).
 *
 * @internal
 */
final class ActionOutcomeClassifier implements ExpectedFailureClassifierInterface
{
    #[Override]
    public function isExpectedFailure(Throwable $e): bool
    {
        return ActionOutcome::of($e) === ActionOutcome::ExpectedFailure;
    }
}
